<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Tests\Unit;

use OCA\BirthdayReminder\Service\CsvExporter;
use PHPUnit\Framework\TestCase;

final class CsvExporterTest extends TestCase {
    public function testProducesSemicolonDelimitedCsvWithBom(): void {
        $csv = (new CsvExporter())->toCsv(['Vorname', 'Nachname'], [['Anna', 'Muster']]);

        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $withoutBom = substr($csv, 3);
        self::assertSame("Vorname;Nachname\nAnna;Muster\n", $withoutBom);
    }

    public function testEscapesValuesContainingDelimiter(): void {
        $csv = (new CsvExporter())->toCsv(['Bemerkung'], [['enthält; ein Semikolon']]);

        self::assertStringContainsString('"enthält; ein Semikolon"', $csv);
    }

    public function testEmptyRowsProduceHeaderOnly(): void {
        $csv = (new CsvExporter())->toCsv(['A', 'B'], []);

        self::assertSame("\xEF\xBB\xBFA;B\n", $csv);
    }
}
