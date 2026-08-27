<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Controller;

use DateTimeImmutable;
use OCA\BirthdayReminder\Db\Member;
use OCA\BirthdayReminder\Db\MemberMapper;
use OCA\BirthdayReminder\Db\ReminderLog;
use OCA\BirthdayReminder\Db\ReminderLogMapper;
use OCA\BirthdayReminder\Service\CsvImportService;
use OCA\BirthdayReminder\Service\CsvParser;
use OCA\BirthdayReminder\Service\ReminderCalculator;
use OCA\BirthdayReminder\Service\ReminderService;
use OCA\BirthdayReminder\Settings\MemberAreaAccess;
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
        private ReminderCalculator $calculator,
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
     * @param array{firstName: string, lastName: string, birthdate: string, email: string} $mapping
     */
    #[AuthorizedAdminSetting(settings: MemberAreaAccess::class)]
    public function importMembers(string $csvContent, array $mapping, ?string $delimiter = null): JSONResponse {
        $delimiter = $delimiter !== null && $delimiter !== '' ? $delimiter : $this->csvParser->guessDelimiter($csvContent);
        $result = $this->csvImportService->import($csvContent, $delimiter, $mapping);
        return new JSONResponse($result);
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

        $todayForAge = new DateTimeImmutable('today');
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
