<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Service;

use DateTimeImmutable;

/**
 * Pure logic deciding whether the daily run is due - no I/O, no Nextcloud
 * runtime dependency, unit-testable. Kept separate from DailyReminderJob so
 * the "once per day, at/after a configured time" gating can be verified
 * without a TimedJob/background-job runtime.
 */
final class ScheduleGate {
    /**
     * @param string $configuredTime "HH:MM", 24h
     * @param string|null $lastRunDate "Y-m-d" of the last completed run, or null
     */
    public function shouldRunNow(string $configuredTime, DateTimeImmutable $now, ?string $lastRunDate): bool {
        if ($lastRunDate === $now->format('Y-m-d')) {
            return false; // already completed today
        }

        $parts = explode(':', $configuredTime);
        if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
            return true; // malformed config: fail open rather than never running
        }

        $target = $now->setTime((int)$parts[0], (int)$parts[1], 0);

        return $now >= $target;
    }
}
