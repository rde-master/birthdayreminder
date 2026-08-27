<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Service;

/**
 * Pure CSV serialization - no I/O beyond an in-memory stream, no Nextcloud
 * runtime dependency, unit-testable. Semicolon-delimited to match German
 * Excel conventions, same as the CSV import's default (CsvParser).
 */
final class CsvExporter {
    /**
     * @param string[] $header
     * @param list<string[]> $rows
     */
    public function toCsv(array $header, array $rows, string $delimiter = ';'): string {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel detects the encoding correctly
        fputcsv($stream, $header, $delimiter, '"', '\\');
        foreach ($rows as $row) {
            fputcsv($stream, $row, $delimiter, '"', '\\');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        return $csv;
    }
}
