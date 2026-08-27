<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Controller;

use OCA\BirthdayReminder\Db\Member;
use OCA\BirthdayReminder\Db\MemberMapper;
use OCA\BirthdayReminder\Db\ReminderLog;
use OCA\BirthdayReminder\Db\ReminderLogMapper;
use OCA\BirthdayReminder\Service\CsvImportService;
use OCA\BirthdayReminder\Service\CsvParser;
use OCA\BirthdayReminder\Service\ReminderService;
use OCA\BirthdayReminder\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class MembersApiController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private MemberMapper $memberMapper,
        private CsvImportService $csvImportService,
        private CsvParser $csvParser,
        private ReminderLogMapper $reminderLogMapper,
        private ReminderService $reminderService,
    ) {
        parent::__construct($appName, $request);
    }

    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function getMembers(): JSONResponse {
        return new JSONResponse(array_map(
            fn (Member $m) => $this->serializeMember($m),
            $this->memberMapper->findAll()
        ));
    }

    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
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

    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
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
     * @param array{firstName: string, lastName: string, birthdate: string, email: string} $mapping
     */
    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function importMembers(string $csvContent, array $mapping, ?string $delimiter = null): JSONResponse {
        $delimiter = $delimiter !== null && $delimiter !== '' ? $delimiter : $this->csvParser->guessDelimiter($csvContent);
        $result = $this->csvImportService->import($csvContent, $delimiter, $mapping);
        return new JSONResponse($result);
    }

    /**
     * Upcoming birthdays of active members, bucketed into "today" / "next 7
     * days" / "next 30 days" (non-overlapping) for the Übersicht page.
     */
    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
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

        return new JSONResponse(['today' => $today, 'next7' => $next7, 'next30' => $next30]);
    }

    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function getSendLog(): JSONResponse {
        $logs = $this->reminderLogMapper->findRecent(200);

        $memberNames = [];
        foreach ($this->memberMapper->findAll() as $member) {
            $memberNames[(string)$member->getId()] = $member->getDisplayName();
        }

        return new JSONResponse(array_map(function (ReminderLog $log) use ($memberNames): array {
            return [
                'id' => $log->getId(),
                'memberName' => $memberNames[$log->getContactUid()] ?? ('Unbekannt/gelöscht (ID ' . $log->getContactUid() . ')'),
                'reminderType' => $log->getReminderType(),
                'daysBefore' => $log->getDaysBefore() === ReminderLog::NO_OFFSET ? null : $log->getDaysBefore(),
                'birthdayYear' => $log->getBirthdayYear(),
                'sentAt' => $log->getSentAt(),
            ];
        }, $logs));
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
