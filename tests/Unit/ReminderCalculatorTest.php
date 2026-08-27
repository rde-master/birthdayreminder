<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Tests\Unit;

use DateTimeImmutable;
use OCA\BirthdayReminder\Model\Member;
use OCA\BirthdayReminder\Service\ReminderCalculator;
use PHPUnit\Framework\TestCase;

final class ReminderCalculatorTest extends TestCase {
    private ReminderCalculator $calculator;

    protected function setUp(): void {
        $this->calculator = new ReminderCalculator();
    }

    public function testMatchesExactOffset(): void {
        $member = new Member('u1', 'Anna Muster', 'anna@example.com', 3, 15, 1990);
        $today = new DateTimeImmutable('2026-03-01');

        $matches = $this->calculator->findMatches([$member], [14], $today);

        self::assertCount(1, $matches);
        self::assertSame(14, $matches[0]['daysBefore']);
        self::assertSame(36, $matches[0]['age']);
    }

    public function testNoMatchWhenOffsetDoesNotLandOnBirthday(): void {
        $member = new Member('u1', 'Anna Muster', 'anna@example.com', 3, 15, 1990);
        $today = new DateTimeImmutable('2026-03-01');

        $matches = $this->calculator->findMatches([$member], [10], $today);

        self::assertCount(0, $matches);
    }

    public function testHandlesYearBoundaryWraparound(): void {
        // Birthday Jan 3, "30 days before" checked on Dec 4 of the previous year.
        $member = new Member('u1', 'Jan Silvester', null, 1, 3, 1985);
        $today = new DateTimeImmutable('2025-12-04');

        $matches = $this->calculator->findMatches([$member], [30], $today);

        self::assertCount(1, $matches);
        self::assertSame('2026-01-03', $matches[0]['targetDate']->format('Y-m-d'));
        self::assertSame(41, $matches[0]['age']);
    }

    public function testLeapDayBirthdayFallsBackToFeb28InNonLeapYear(): void {
        $member = new Member('u1', 'Leo Schalt', null, 2, 29, 2000);
        $today = new DateTimeImmutable('2027-02-28');

        $matches = $this->calculator->findMatches([$member], [0], $today);

        self::assertCount(1, $matches);
    }

    public function testAgeIsNullWhenBirthYearUnknown(): void {
        $member = new Member('u1', 'Ohne Jahr', null, 6, 1, null);
        $today = new DateTimeImmutable('2026-06-01');

        $matches = $this->calculator->findMatches([$member], [0], $today);

        self::assertCount(1, $matches);
        self::assertNull($matches[0]['age']);
    }

    public function testMultipleOffsetsCheckedIndependently(): void {
        $member = new Member('u1', 'Anna Muster', 'anna@example.com', 3, 15, 1990);
        $today = new DateTimeImmutable('2026-03-01');

        $matches = $this->calculator->findMatches([$member], [30, 14, 2, 1, 0], $today);

        self::assertCount(1, $matches);
        self::assertSame(14, $matches[0]['daysBefore']);
    }

    public function testIsMilestoneAge(): void {
        self::assertTrue($this->calculator->isMilestoneAge(18, [18, 30, 50]));
        self::assertFalse($this->calculator->isMilestoneAge(19, [18, 30, 50]));
        self::assertFalse($this->calculator->isMilestoneAge(null, [18, 30, 50]));
    }

    public function testDaysUntilNextBirthdayLaterThisYear(): void {
        $member = new Member('u1', 'Anna Muster', 'anna@example.com', 3, 15, 1990);
        $today = new DateTimeImmutable('2026-03-01');

        $result = $this->calculator->daysUntilNextBirthday($member, $today);

        self::assertSame(14, $result['daysUntil']);
        self::assertSame('2026-03-15', $result['targetDate']->format('Y-m-d'));
        self::assertSame(36, $result['age']);
    }

    public function testDaysUntilNextBirthdayIsZeroToday(): void {
        $member = new Member('u1', 'Anna Muster', null, 3, 15, 1990);
        $today = new DateTimeImmutable('2026-03-15');

        $result = $this->calculator->daysUntilNextBirthday($member, $today);

        self::assertSame(0, $result['daysUntil']);
    }

    public function testDaysUntilNextBirthdayRollsOverToNextYearWhenAlreadyPassed(): void {
        $member = new Member('u1', 'Anna Muster', null, 3, 15, 1990);
        $today = new DateTimeImmutable('2026-03-16');

        $result = $this->calculator->daysUntilNextBirthday($member, $today);

        self::assertSame('2027-03-15', $result['targetDate']->format('Y-m-d'));
        self::assertSame(37, $result['age']);
    }

    public function testDaysUntilNextBirthdayHandlesLeapDay(): void {
        $member = new Member('u1', 'Leo Schalt', null, 2, 29, 2000);
        $today = new DateTimeImmutable('2027-02-01'); // 2027 is not a leap year

        $result = $this->calculator->daysUntilNextBirthday($member, $today);

        self::assertSame('2027-02-28', $result['targetDate']->format('Y-m-d'));
    }

    public function testCurrentAgeAfterBirthdayThisYear(): void {
        $today = new DateTimeImmutable('2026-03-20');

        self::assertSame(36, $this->calculator->currentAge(3, 15, 1990, $today));
    }

    public function testCurrentAgeBeforeBirthdayThisYear(): void {
        $today = new DateTimeImmutable('2026-03-10');

        self::assertSame(35, $this->calculator->currentAge(3, 15, 1990, $today));
    }

    public function testCurrentAgeOnBirthdayItself(): void {
        $today = new DateTimeImmutable('2026-03-15');

        self::assertSame(36, $this->calculator->currentAge(3, 15, 1990, $today));
    }

    public function testAgeBucketIndexFirstBucketIsElevenWide(): void {
        self::assertSame(0, $this->calculator->ageBucketIndex(0));
        self::assertSame(0, $this->calculator->ageBucketIndex(10));
        self::assertSame(1, $this->calculator->ageBucketIndex(11));
        self::assertSame(1, $this->calculator->ageBucketIndex(20));
        self::assertSame(2, $this->calculator->ageBucketIndex(21));
        self::assertSame(2, $this->calculator->ageBucketIndex(30));
        self::assertSame(9, $this->calculator->ageBucketIndex(91));
    }
}
