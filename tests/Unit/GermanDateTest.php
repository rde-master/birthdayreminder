<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Tests\Unit;

use DateTimeImmutable;
use OCA\BirthdayReminder\Service\GermanDate;
use PHPUnit\Framework\TestCase;

final class GermanDateTest extends TestCase {
    public function testWeekdayNames(): void {
        self::assertSame('Montag', GermanDate::weekdayName(new DateTimeImmutable('2026-08-24')));
        self::assertSame('Mittwoch', GermanDate::weekdayName(new DateTimeImmutable('2026-08-26')));
        self::assertSame('Sonntag', GermanDate::weekdayName(new DateTimeImmutable('2026-08-30')));
    }
}
