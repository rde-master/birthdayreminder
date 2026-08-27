<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Service;

/**
 * Pure diffing logic for the CSV/Contacts import - no I/O, no Nextcloud
 * runtime dependency, unit-testable. Matches members primarily by e-mail
 * address (case-insensitive) when both sides have one - the more stable
 * identity, and what prevents duplicate contacts on repeated Kontakte-
 * Import/-Export round trips even if a display name changed slightly.
 * Falls back to first+last name (case-insensitive) when no e-mail match is
 * available, e.g. for CSV rows without an e-mail column. Decides
 * insert/update/leave-alone/disable, and never re-enables a member
 * automatically - only a human toggles that back on.
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
        $existingByEmail = [];
        $existingByName = [];
        foreach ($existingMembers as $member) {
            if ($member['email'] !== null && $member['email'] !== '') {
                $existingByEmail[self::emailKey($member['email'])] = $member;
            }
            $existingByName[self::nameKey($member['firstName'], $member['lastName'])] = $member;
        }

        $inserts = [];
        $updates = [];
        $seenIds = [];
        $unchangedCount = 0;

        foreach ($parsedRows as $row) {
            $existing = null;
            if ($row['email'] !== null && $row['email'] !== '') {
                $existing = $existingByEmail[self::emailKey($row['email'])] ?? null;
            }
            $existing ??= $existingByName[self::nameKey($row['firstName'], $row['lastName'])] ?? null;

            if ($existing === null) {
                $inserts[] = $row;
                continue;
            }

            $seenIds[$existing['id']] = true;

            if ($existing['birthDay'] === $row['birthDay']
                && $existing['birthMonth'] === $row['birthMonth']
                && $existing['birthYear'] === $row['birthYear']
                && self::emailsEqual($existing['email'], $row['email'])
                && self::nameKey($existing['firstName'], $existing['lastName']) === self::nameKey($row['firstName'], $row['lastName'])
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
            if (isset($seenIds[$member['id']]) || $member['disabled']) {
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

    private static function nameKey(string $firstName, string $lastName): string {
        return mb_strtolower(trim($firstName)) . '|' . mb_strtolower(trim($lastName));
    }

    private static function emailKey(string $email): string {
        return mb_strtolower(trim($email));
    }

    private static function emailsEqual(?string $a, ?string $b): bool {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        return self::emailKey($a) === self::emailKey($b);
    }
}
