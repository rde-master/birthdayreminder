<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Service;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Wraps "now" so every entry point (scheduled job, external cron trigger,
 * manual admin trigger, Übersicht page) agrees on what "today"/"the current
 * time" means.
 *
 * Nextcloud forces PHP's default timezone to UTC on every single request
 * (lib/base.php calls date_default_timezone_set('UTC')) - a naive
 * `new DateTimeImmutable('now')` therefore always resolves to UTC,
 * regardless of the server's actually configured timezone. Found the hard
 * way: this silently shifted the admin-configured "Tägliche Prüfzeit" by
 * the UTC offset (e.g. "06:00" only fired at 08:00 local time during CEST)
 * and could shift which calendar day member birthdays match against during
 * the few hours around local midnight.
 *
 * Fix: read the server's real configured timezone from php.ini's
 * date.timezone - that ini value is unaffected by date_default_timezone_set()
 * at runtime, since that function only changes PHP's *default*, not the ini
 * setting itself - and construct "now" in that timezone explicitly.
 */
final class ClockService {
    public function now(): DateTimeImmutable {
        return new DateTimeImmutable('now', $this->serverTimeZone());
    }

    /** Midnight, start of today - in the server's real timezone, not UTC. */
    public function today(): DateTimeImmutable {
        return $this->now()->setTime(0, 0, 0);
    }

    private function serverTimeZone(): DateTimeZone {
        $name = ini_get('date.timezone');
        return new DateTimeZone($name !== false && $name !== '' ? $name : 'UTC');
    }
}
