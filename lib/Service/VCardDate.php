<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Service;

/**
 * Pure conversion between our (day, month, ?year) triple and the raw
 * vCard BDAY value format - no I/O, no Nextcloud runtime dependency,
 * unit-testable.
 *
 * Deliberately writes/reads the *raw* mimedir value (e.g. "19900315" or
 * "--0315"), not an ISO string with dashes for the full-date case - that's
 * the canonical vCard 3/4 form (see RFC 6350 §4.3.1) and what Sabre\VObject
 * round-trips through Property\VCard\DateAndOrTime without reformatting,
 * since that property type stores whatever raw string it's given.
 */
final class VCardDate {
    /**
     * @return array{day: int, month: int, year: ?int}|null
     */
    public static function parse(string $raw): ?array {
        $raw = trim($raw);

        if (preg_match('/^(\d{4})-?(\d{2})-?(\d{2})$/', $raw, $m) === 1) {
            [$year, $month, $day] = [(int)$m[1], (int)$m[2], (int)$m[3]];
            return checkdate($month, $day, $year) ? ['day' => $day, 'month' => $month, 'year' => $year] : null;
        }

        if (preg_match('/^--(\d{2})-?(\d{2})$/', $raw, $m) === 1) {
            [$month, $day] = [(int)$m[1], (int)$m[2]];
            return checkdate($month, $day, 2000) ? ['day' => $day, 'month' => $month, 'year' => null] : null;
        }

        return null;
    }

    public static function format(int $day, int $month, ?int $year): string {
        return $year !== null
            ? sprintf('%04d%02d%02d', $year, $month, $day)
            : sprintf('--%02d%02d', $month, $day);
    }
}
