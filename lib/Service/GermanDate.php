<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Service;

use DateTimeImmutable;

/**
 * Pure formatting helper - no I/O, no Nextcloud runtime dependency,
 * unit-testable. PHP's DateTimeImmutable::format('l') only gives English
 * weekday names regardless of locale, so a small fixed lookup is simplest
 * (avoids depending on the intl extension being available on shared hosting).
 */
final class GermanDate {
    private const WEEKDAYS = [
        'Monday' => 'Montag',
        'Tuesday' => 'Dienstag',
        'Wednesday' => 'Mittwoch',
        'Thursday' => 'Donnerstag',
        'Friday' => 'Freitag',
        'Saturday' => 'Samstag',
        'Sunday' => 'Sonntag',
    ];

    public static function weekdayName(DateTimeImmutable $date): string {
        return self::WEEKDAYS[$date->format('l')] ?? $date->format('l');
    }
}
