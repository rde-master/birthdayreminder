<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Tests\Unit;

use OCA\BirthdayReminder\Contacts\ContactsGateway;
use PHPUnit\Framework\TestCase;

final class ContactsGatewayBdayParsingTest extends TestCase {
    public function testParsesNoYearForm(): void {
        self::assertSame(['month' => 3, 'day' => 15, 'year' => null], ContactsGateway::parseBirthday('--0315'));
    }

    public function testParsesNoYearFormWithDashes(): void {
        self::assertSame(['month' => 3, 'day' => 15, 'year' => null], ContactsGateway::parseBirthday('--03-15'));
    }

    public function testParsesFullDateCompact(): void {
        self::assertSame(['month' => 3, 'day' => 15, 'year' => 1990], ContactsGateway::parseBirthday('19900315'));
    }

    public function testParsesFullDateWithDashes(): void {
        self::assertSame(['month' => 3, 'day' => 15, 'year' => 1990], ContactsGateway::parseBirthday('1990-03-15'));
    }

    public function testParsesFullDateWithTimeComponent(): void {
        self::assertSame(['month' => 3, 'day' => 15, 'year' => 1990], ContactsGateway::parseBirthday('1990-03-15T00:00:00Z'));
    }

    public function testRejectsInvalidCalendarDate(): void {
        self::assertNull(ContactsGateway::parseBirthday('1990-02-30'));
    }

    public function testRejectsFreeText(): void {
        self::assertNull(ContactsGateway::parseBirthday('circa 1990'));
    }

    public function testRejectsEmptyString(): void {
        self::assertNull(ContactsGateway::parseBirthday(''));
    }
}
