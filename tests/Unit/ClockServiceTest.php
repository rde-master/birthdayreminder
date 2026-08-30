<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Tests\Unit;

use OCA\BirthdayReminder\Service\ClockService;
use PHPUnit\Framework\TestCase;

final class ClockServiceTest extends TestCase {
    private ClockService $clock;

    protected function setUp(): void {
        $this->clock = new ClockService();
    }

    public function testNowUsesTheConfiguredPhpIniTimezoneNotTheRuntimeDefault(): void {
        // Nextcloud forces PHP's runtime default timezone to UTC on every
        // request (this is what the bug was) - simulate that here and
        // confirm ClockService still resolves to php.ini's date.timezone
        // rather than inheriting the (wrong) runtime default.
        $original = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            $expected = ini_get('date.timezone');
            $expected = $expected !== false && $expected !== '' ? $expected : 'UTC';

            self::assertSame($expected, $this->clock->now()->getTimezone()->getName());
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function testTodayIsMidnight(): void {
        $today = $this->clock->today();

        self::assertSame('00:00:00', $today->format('H:i:s'));
    }

    public function testTodayIsSameCalendarDateAsNow(): void {
        self::assertSame($this->clock->now()->format('Y-m-d'), $this->clock->today()->format('Y-m-d'));
    }
}
