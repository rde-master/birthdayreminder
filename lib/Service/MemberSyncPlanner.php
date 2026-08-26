<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Service;

/**
 * Pure diffing logic for the CSV import - no I/O, no Nextcloud runtime
 * dependency, unit-testable. Matches members by first+last name (case-
 * insensitive), decides insert/update/leave-alone/disable, and never
 * re-enables a member automatically - only a human toggles that back on.
 */
final class MemberSyncPlanner {
    public const AUTO_DISABLE_REMARK = 'Deaktiviert da bei Import nicht mehr vorhanden';

    /**
     * @param array<int, array{id: int, firstName: string, lastName: string, birthDay: int, birthMonth: int, birthYear: ?int, email: ?string, disabled: bool, remark: ?string}> $existingMembers
     * @param array<int, array{firstName: string, lastName: string, birthDay: int, birthMonth: int, birthYear: ?int, email: ?string}> $parsedRows
     * @return array{
     *     inserts: list<array{firstName: string, lastName: string, birthDay: int, birthMonth: int, birthYear: ?int, email: ?string}>,
     *     updates: list<array{id: int, firstName: string, lastName: string, birthDay: int, birthMonth: int, birthYear: ?int, email: ?string}>,
     *     disables: list<array{id: int, remark: ?string}>,
     *     unchangedCount: int,
     * }
     */
    public function plan(array $existingMembers, array $parsedRows): array {
        $existingByKey = [];
        foreach ($existingMembers as $member) {
            $existingByKey[self::key($member['firstName'], $member['lastName'])] = $member;
        }

        $inserts = [];
        $updates = [];
        $seenKeys = [];
        $unchangedCount = 0;

        foreach ($parsedRows as $row) {
            $key = self::key($row['firstName'], $row['lastName']);
            $seenKeys[$key] = true;
            $existing = $existingByKey[$key] ?? null;

            if ($existing === null) {
                $inserts[] = $row;
                continue;
            }

            if ($existing['birthDay'] === $row['birthDay']
                && $existing['birthMonth'] === $row['birthMonth']
                && $existing['birthYear'] === $row['birthYear']
                && $existing['email'] === $row['email']
            ) {
                $unchangedCount++;
                continue;
            }

            $updates[] = [
                'id' => $existing['id'],
                'firstName' => $row['firstName'],
                'lastName' => $row['lastName'],
                'birthDay' => $row['birthDay'],
                'birthMonth' => $row['birthMonth'],
                'birthYear' => $row['birthYear'],
                'email' => $row['email'],
            ];
        }

        $disables = [];
        foreach ($existingMembers as $member) {
            $key = self::key($member['firstName'], $member['lastName']);
            if (isset($seenKeys[$key]) || $member['disabled']) {
                continue;
            }
            $disables[] = [
                'id' => $member['id'],
                'remark' => self::appendAutoDisableRemark($member['remark']),
            ];
        }

        return [
            'inserts' => $inserts,
            'updates' => $updates,
            'disables' => $disables,
            'unchangedCount' => $unchangedCount,
        ];
    }

    public static function appendAutoDisableRemark(?string $existingRemark): string {
        $existingRemark = trim((string)$existingRemark);
        if ($existingRemark === '') {
            return self::AUTO_DISABLE_REMARK;
        }
        if (str_contains($existingRemark, self::AUTO_DISABLE_REMARK)) {
            return $existingRemark;
        }
        return $existingRemark . '; ' . self::AUTO_DISABLE_REMARK;
    }

    private static function key(string $firstName, string $lastName): string {
        return mb_strtolower(trim($firstName)) . '|' . mb_strtolower(trim($lastName));
    }
}
