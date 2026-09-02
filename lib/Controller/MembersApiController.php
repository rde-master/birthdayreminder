<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Controller;

use OCA\BirthdayReminder\Contacts\ContactsGateway;
use OCA\BirthdayReminder\Db\Member;
use OCA\BirthdayReminder\Db\MemberMapper;
use OCA\BirthdayReminder\Db\Milestone;
use OCA\BirthdayReminder\Db\MilestoneMapper;
use OCA\BirthdayReminder\Db\ReminderLog;
use OCA\BirthdayReminder\Db\ReminderLogMapper;
use OCA\BirthdayReminder\Service\ClockService;
use OCA\BirthdayReminder\Service\CsvExporter;
use OCA\BirthdayReminder\Service\CsvImportService;
use OCA\BirthdayReminder\Service\CsvParser;
use OCA\BirthdayReminder\Service\ReminderCalculator;
use OCA\BirthdayReminder\Service\ReminderService;
use OCA\BirthdayReminder\Settings\MemberAreaAccess;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class MembersApiController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private MemberMapper $memberMapper,
        private CsvImportService $csvImportService,
        private CsvParser $csvParser,
        private CsvExporter $csvExporter,
        private ContactsGateway $contactsGateway,
        private ReminderLogMapper $reminderLogMapper,
        private ReminderService $reminderService,
        private ReminderCalculator $calculator,
        private MilestoneMapper $milestoneMapper,
        private ClockService $clockService,
    ) {
        parent::__construct($appName, $request);
    }

    #[AuthorizedAdminSetting(settings: MemberAreaAccess::class)]
    public function getMembers(): JSONResponse {
        return new JSONResponse(array_map(
            fn (Member $m) => $this->serializeMember($m),
            $this->memberMapper->findAll()
        ));
    }

    #[AuthorizedAdminSetting(settings: MemberAreaAccess::class)]
    public function saveMember(
        ?int $id,
        string $firstName,
        string $lastName,
        int $birthDay,
        int $birthMonth,
        ?int $birthYear,
        ?string $email,
        bool $disabled,
        ?string $remark,
    ): JSONResponse {
        $firstName = trim($firstName);
        $lastName = trim($lastName);
        if ($firstName === '' || $lastName === '') {
            return new JSONResponse(['error' => 'Vorname und Nachname sind erforderlich'], 400);
        }
        if (!checkdate($birthMonth, $birthDay, $birthYear ?? 2000)) {
            return new JSONResponse(['error' => 'Ungültiges Geburtsdatum'], 400);
        }

        if ($id !== null) {
            try {
                $member = $this->memberMapper->find($id);
            } catch (DoesNotExistException) {
                return new JSONResponse(['error' => 'not found'], 404);
            }
        } else {
            $member = new Member();
            $member->setCreatedAt(time());
        }

        $email = $email !== null ? trim($email) : null;

        $member->setFirstName($firstName);
        $member->setLastName($lastName);
        $member->setBirthDay($birthDay);
        $member->setBirthMonth($birthMonth);
        $member->setBirthYear($birthYear);
        $member->setEmail($email !== '' ? $email : null);
        $member->setDisabled($disabled);
        $member->setRemark($remark !== null && trim($remark) !== '' ? trim($remark) : null);
        $member->setUpdatedAt(time());

        $saved = $member->getId() === null ? $this->memberMapper->insert($member) : $this->memberMapper->update($member);

        return new JSONResponse($this->serializeMember($saved));
    }

    #[AuthorizedAdminSetting(settings: MemberAreaAccess::class)]
    public function deleteMember(int $id): JSONResponse {
        try {
            $member = $this->memberMapper->find($id);
        } catch (DoesNotExistException) {
            return new JSONResponse(['error' => 'not found'], 404);
        }
        $this->memberMapper->delete($member);
        return new JSONResponse(['ok' => true]);
    }

    /**
     * A blank-to-fill-in CSV matching exactly what the CSV import expects,
     * with two example rows demonstrating both supported Geburtsdatum
     * formats (with and without a known year) and an empty-E-Mail row.
     *
     * NoCSRFRequired: this is reached via a plain <a href> download link,
     * not the JS fetch() helper that attaches the requesttoken header -
     * a read-only GET with no side effects, so exempting it is safe.
     */
    #[NoCSRFRequired]
    #[AuthorizedAdminSetting(settings: MemberAreaAccess::class)]
    public function importTemplateCsv(): DataDownloadResponse {
        $rows = [
            ['Max', 'Mustermann', '15.03.1990', 'max.mustermann@example.com'],
            ['Erika', 'Musterfrau', '03.11.', ''],
        ];
        $csv = $this->csvExporter->toCsv(['Vorname', 'Nachname', 'Geburtsdatum', 'E-Mail'], $rows);
        return new DataDownloadResponse($csv, 'mitglieder-import-vorlage.csv', 'text/csv; charset=UTF-8');
    }

    /**
     * @param array{firstName: string, lastName: string, birthdate: string, email: string} $mapping
     */
    #[AuthorizedAdminSetting(settings: MemberAreaAccess::class)]
    public function importMembers(string $csvContent, array $mapping, ?string $delimiter = null): JSONResponse {
        $delimiter = $delimiter !== null && $delimiter !== '' ? $delimiter : $this->csvParser->guessDelimiter($csvContent);
        $result = $this->csvImportService->import($csvContent, $delimiter, $mapping);
        return new JSONResponse($result);
    }

    /**
     * Imports from the current user's own Nextcloud contacts (all of their
     * personal/shared address books, excluding the system book). Shares the
     * exact same diff/apply logic as the CSV import (CsvImportService::
     * applyParsedRows()), so the same insert/update/unchanged/auto-disable
     * rules apply.
     */
    #[AuthorizedAdminSetting(settings: MemberAreaAccess::class)]
    public function importContacts(): JSONResponse {
        $parsed = $this->contactsGateway->importFromUserContacts();
        $result = $this->csvImportService->applyParsedRows($parsed['rows'], $parsed['errors']);
        return new JSONResponse($result);
    }

    /**
     * Exports all active members into the current user's own writable
     * personal address book (creating or updating by matching full name).
     */
    #[AuthorizedAdminSetting(settings: MemberAreaAccess::class)]
    public function exportContacts(): JSONResponse {
        try {
            $result = $this->contactsGateway->exportMembersToUserContacts($this->memberMapper->findAllActive());
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
        return new JSONResponse($result);
    }

    #[NoCSRFRequired]
    #[AuthorizedAdminSetting(settings: MemberAreaAccess::class)]
    public function exportMembersCsv(): DataDownloadResponse {
        $rows = array_map(function (Member $m): array {
            return [
                $m->getFirstName(),
                $m->getLastName(),
                $this->formatBirthdateForCsv($m->getBirthDay(), $m->getBirthMonth(), $m->getBirthYear()),
                $m->getEmail() ?? '',
                $m->getDisabled() ? 'Ja' : 'Nein',
                $m->getRemark() ?? '',
            ];
        }, $this->memberMapper->findAll());

        $csv = $this->csvExporter->toCsv(['Vorname', 'Nachname', 'Geburtsdatum', 'E-Mail', 'Deaktiviert', 'Bemerkung'], $rows);
        return new DataDownloadResponse($csv, 'mitglieder.csv', 'text/csv; charset=UTF-8');
    }

    private function formatBirthdateForCsv(int $day, int $month, ?int $year): string {
        $dd = str_pad((string)$day, 2, '0', STR_PAD_LEFT);
        $mm = str_pad((string)$month, 2, '0', STR_PAD_LEFT);
        return $year !== null ? "{$dd}.{$mm}.{$year}" : "{$dd}.{$mm}.";
    }

    /**
     * Upcoming birthdays of active members, bucketed into "today" / "next 7
     * days" / "next 30 days" (non-overlapping), plus a per-month birthday
     * count (index 0 = January) across all active members - for the
     * Übersicht page.
     */
    #[AuthorizedAdminSetting(settings: MemberAreaAccess::class)]
    public function getOverview(): JSONResponse {
        $upcoming = $this->reminderService->getUpcomingBirthdaysWithinDays(30);

        $today = [];
        $next7 = [];
        $next30 = [];
        foreach ($upcoming as $entry) {
            $item = [
                'name' => $entry['member']->displayName,
                'date' => $entry['targetDate']->format('d.m.Y'),
                'age' => $entry['age'],
                'daysUntil' => $entry['daysUntil'],
            ];
            if ($entry['daysUntil'] === 0) {
                $today[] = $item;
            } elseif ($entry['daysUntil'] <= 7) {
                $next7[] = $item;
            } else {
                $next30[] = $item;
            }
        }

        $activeMembers = $this->memberMapper->findAllActive();

        $monthCounts = array_fill(0, 12, 0);
        foreach ($activeMembers as $member) {
            $monthCounts[$member->getBirthMonth() - 1]++;
        }

        $todayForAge = $this->clockService->today();
        $ageCounts = [];
        $unknownAge = 0;
        foreach ($activeMembers as $member) {
            $year = $member->getBirthYear();
            if ($year === null) {
                $unknownAge++;
                continue;
            }
            $age = max(0, $this->calculator->currentAge($member->getBirthMonth(), $member->getBirthDay(), $year, $todayForAge));
            $bucket = $this->calculator->ageBucketIndex($age);
            $ageCounts[$bucket] = ($ageCounts[$bucket] ?? 0) + 1;
        }
        $maxBucket = empty($ageCounts) ? -1 : max(array_keys($ageCounts));
        $ageBuckets = [];
        for ($i = 0; $i <= $maxBucket; $i++) {
            $ageBuckets[] = $ageCounts[$i] ?? 0;
        }

        return new JSONResponse([
            'today' => $today,
            'next7' => $next7,
            'next30' => $next30,
            'monthCounts' => $monthCounts,
            'ageBuckets' => $ageBuckets,
            'unknownAge' => $unknownAge,
        ]);
    }

    #[AuthorizedAdminSetting(settings: MemberAreaAccess::class)]
    public function getSendLog(): JSONResponse {
        return new JSONResponse($this->buildLogRows());
    }

    #[NoCSRFRequired]
    #[AuthorizedAdminSetting(settings: MemberAreaAccess::class)]
    public function exportSendLogCsv(): DataDownloadResponse {
        $rows = array_map(function (array $entry): array {
            return [
                $entry['memberName'],
                self::formatLogTypeForExport($entry['reminderType']),
                $entry['daysBefore'] === null ? '' : (string)$entry['daysBefore'],
                (string)$entry['birthdayYear'],
                $entry['recipientEmail'],
                date('d.m.Y H:i', $entry['sentAt']),
            ];
        }, $this->buildLogRows());

        $csv = $this->csvExporter->toCsv(['Mitglied', 'Art', 'Vorlaufzeit (Tage)', 'Bezugsjahr', 'Empfänger', 'Gesendet am'], $rows);
        return new DataDownloadResponse($csv, 'versand-log.csv', 'text/csv; charset=UTF-8');
    }

    private static function formatLogTypeForExport(string $reminderType): string {
        return match ($reminderType) {
            ReminderLog::TYPE_CONGRATS => 'Glückwunsch ans Mitglied',
            ReminderLog::TYPE_NONE => 'Kein Versand (nichts fällig)',
            default => 'Erinnerung an Verantwortliche',
        };
    }

    /**
     * @return list<array{id: int, memberName: string, reminderType: string, daysBefore: ?int, birthdayYear: int, recipientEmail: string, sentAt: int}>
     */
    private function buildLogRows(): array {
        $logs = $this->reminderLogMapper->findRecent(200);

        $memberNames = [];
        foreach ($this->memberMapper->findAll() as $member) {
            $memberNames[(string)$member->getId()] = $member->getDisplayName();
        }

        return array_map(function (ReminderLog $log) use ($memberNames): array {
            $isNoneMarker = $log->getReminderType() === ReminderLog::TYPE_NONE;
            return [
                'id' => $log->getId(),
                'memberName' => $isNoneMarker
                    ? '–'
                    : ($memberNames[$log->getContactUid()] ?? ('Unbekannt/gelöscht (ID ' . $log->getContactUid() . ')')),
                'reminderType' => $log->getReminderType(),
                'daysBefore' => $log->getDaysBefore() === ReminderLog::NO_OFFSET ? null : $log->getDaysBefore(),
                'birthdayYear' => $log->getBirthdayYear(),
                // Pre-existing rows from before this column was added have no
                // recorded recipient (NULL) - render that the same as the
                // TYPE_NONE sentinel rather than exposing NULL to the API.
                'recipientEmail' => $log->getRecipientEmail() ?? '',
                'sentAt' => $log->getSentAt(),
            ];
        }, $logs);
    }

    /**
     * Read-only view of the milestone gift suggestions for the "Geschenke"
     * page - editing stays exclusive to the Admin-Einstellungen
     * (AdminApiController::saveMilestone/deleteMilestone, gated by the
     * stricter AdminSettings delegation).
     */
    #[AuthorizedAdminSetting(settings: MemberAreaAccess::class)]
    public function getGifts(): JSONResponse {
        return new JSONResponse(array_map(
            fn (Milestone $m) => ['age' => $m->getAge(), 'giftText' => $m->getGiftText()],
            $this->milestoneMapper->findAll()
        ));
    }

    private function serializeMember(Member $m): array {
        return [
            'id' => $m->getId(),
            'firstName' => $m->getFirstName(),
            'lastName' => $m->getLastName(),
            'birthDay' => $m->getBirthDay(),
            'birthMonth' => $m->getBirthMonth(),
            'birthYear' => $m->getBirthYear(),
            'email' => $m->getEmail(),
            'disabled' => $m->getDisabled(),
            'remark' => $m->getRemark(),
        ];
    }
}
