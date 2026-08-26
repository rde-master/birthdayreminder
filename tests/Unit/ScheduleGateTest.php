<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Tests\Unit;

use DateTimeImmutable;
use OCA\BirthdayReminder\Service\ScheduleGate;
use PHPUnit\Framework\TestCase;

final class ScheduleGateTest extends TestCase {
    private ScheduleGate $gate;

    protected function setUp(): void {
        $this->gate = new ScheduleGate();
    }

    public function testDoesNotRunBeforeConfiguredTime(): void {
        $now = new DateTimeImmutable('2026-08-27 07:59:00');
        self::assertFalse($this->gate->shouldRunNow('08:00', $now, null));
    }

    public function testRunsAtConfiguredTime(): void {
        $now = new DateTimeImmutable('2026-08-27 08:00:00');
        self::assertTrue($this->gate->shouldRunNow('08:00', $now, null));
    }

    public function testRunsAfterConfiguredTimeIfNotYetRunToday(): void {
        $now = new DateTimeImmutable('2026-08-27 14:30:00');
        self::assertTrue($this->gate->shouldRunNow('08:00', $now, null));
    }

    public function testDoesNotRunAgainSameDay(): void {
        $now = new DateTimeImmutable('2026-08-27 14:30:00');
        self::assertFalse($this->gate->shouldRunNow('08:00', $now, '2026-08-27'));
    }

    public function testRunsOnANewDayEvenIfRanYesterday(): void {
        $now = new DateTimeImmutable('2026-08-28 08:05:00');
        self::assertTrue($this->gate->shouldRunNow('08:00', $now, '2026-08-27'));
    }

    public function testMalformedConfigFailsOpen(): void {
        $now = new DateTimeImmutable('2026-08-27 03:00:00');
        self::assertTrue($this->gate->shouldRunNow('not-a-time', $now, null));
    }
}
