<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Tests\Unit;

use OCA\BirthdayReminder\Service\VCardDate;
use PHPUnit\Framework\TestCase;

final class VCardDateTest extends TestCase {
    public function testParsesCompactFullDate(): void {
        self::assertSame(['day' => 15, 'month' => 3, 'year' => 1990], VCardDate::parse('19900315'));
    }

    public function testParsesDashedFullDate(): void {
        self::assertSame(['day' => 15, 'month' => 3, 'year' => 1990], VCardDate::parse('1990-03-15'));
    }

    public function testParsesCompactMonthDayOnly(): void {
        self::assertSame(['day' => 15, 'month' => 3, 'year' => null], VCardDate::parse('--0315'));
    }

    public function testParsesDashedMonthDayOnly(): void {
        self::assertSame(['day' => 15, 'month' => 3, 'year' => null], VCardDate::parse('--03-15'));
    }

    public function testRejectsInvalidDate(): void {
        self::assertNull(VCardDate::parse('19900231'));
        self::assertNull(VCardDate::parse('not-a-date'));
        self::assertNull(VCardDate::parse(''));
    }

    public function testFormatsFullDateCompact(): void {
        self::assertSame('19900315', VCardDate::format(15, 3, 1990));
    }

    public function testFormatsMonthDayOnlyCompact(): void {
        self::assertSame('--0315', VCardDate::format(15, 3, null));
    }

    public function testRoundTripsThroughParseAndFormat(): void {
        self::assertSame(['day' => 15, 'month' => 3, 'year' => 1990], VCardDate::parse(VCardDate::format(15, 3, 1990)));
        self::assertSame(['day' => 15, 'month' => 3, 'year' => null], VCardDate::parse(VCardDate::format(15, 3, null)));
    }
}
