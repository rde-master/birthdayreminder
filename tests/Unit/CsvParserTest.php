<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Tests\Unit;

use OCA\BirthdayReminder\Service\CsvParser;
use PHPUnit\Framework\TestCase;

final class CsvParserTest extends TestCase {
    private CsvParser $parser;

    protected function setUp(): void {
        $this->parser = new CsvParser();
    }

    public function testParseRowsSplitsBySemicolon(): void {
        $rows = $this->parser->parseRows("Vorname;Nachname\nAnna;Muster\n", ';');
        self::assertSame([['Vorname', 'Nachname'], ['Anna', 'Muster']], $rows);
    }

    public function testParseRowsStripsUtf8Bom(): void {
        $rows = $this->parser->parseRows("\xEF\xBB\xBFVorname;Nachname\n", ';');
        self::assertSame([['Vorname', 'Nachname']], $rows);
    }

    public function testParseRowsSkipsBlankLines(): void {
        $rows = $this->parser->parseRows("a;b\n\n\nc;d\n", ';');
        self::assertSame([['a', 'b'], ['c', 'd']], $rows);
    }

    public function testGuessDelimiterPrefersSemicolon(): void {
        self::assertSame(';', $this->parser->guessDelimiter("Vorname;Nachname;Geburtsdatum\n"));
    }

    public function testGuessDelimiterFallsBackToComma(): void {
        self::assertSame(',', $this->parser->guessDelimiter("Vorname,Nachname,Geburtsdatum\n"));
    }

    public function testParseGermanDateWithYear(): void {
        self::assertSame(['day' => 15, 'month' => 3, 'year' => 1990], CsvParser::parseGermanDate('15.03.1990'));
    }

    public function testParseGermanDateSingleDigitDayMonth(): void {
        self::assertSame(['day' => 5, 'month' => 3, 'year' => 1990], CsvParser::parseGermanDate('5.3.1990'));
    }

    public function testParseGermanDateWithoutYear(): void {
        self::assertSame(['day' => 15, 'month' => 3, 'year' => null], CsvParser::parseGermanDate('15.03.'));
    }

    public function testParseGermanDateWithoutYearNoTrailingDot(): void {
        self::assertSame(['day' => 15, 'month' => 3, 'year' => null], CsvParser::parseGermanDate('15.03'));
    }

    public function testParseIsoDate(): void {
        self::assertSame(['day' => 15, 'month' => 3, 'year' => 1990], CsvParser::parseGermanDate('1990-03-15'));
    }

    public function testParseGermanDateRejectsInvalidCalendarDate(): void {
        self::assertNull(CsvParser::parseGermanDate('31.02.1990'));
    }

    public function testParseGermanDateRejectsGarbage(): void {
        self::assertNull(CsvParser::parseGermanDate('irgendwas'));
    }
}
