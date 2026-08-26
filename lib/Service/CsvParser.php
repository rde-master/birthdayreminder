<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Service;

/**
 * Pure CSV/date parsing - no I/O, no Nextcloud runtime dependency, unit-testable.
 * Uses one str_getcsv() per line rather than a streaming reader, which is
 * simpler but does not support quoted fields containing literal newlines -
 * fine for the flat, one-row-per-member CSVs this app expects.
 */
final class CsvParser {
    /**
     * @return list<list<string>> rows, including the header row at index 0
     */
    public function parseRows(string $content, string $delimiter): array {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content); // strip UTF-8 BOM
        $lines = preg_split('/\r\n|\r|\n/', (string)$content);

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $rows[] = str_getcsv($line, $delimiter, '"', '\\');
        }
        return $rows;
    }

    /**
     * Guesses the delimiter from the header line: whichever of ';' or ','
     * appears more often. German exports (Excel) typically use ';'.
     */
    public function guessDelimiter(string $content): string {
        $firstLine = strtok($content, "\r\n");
        $firstLine = $firstLine === false ? '' : $firstLine;
        $semicolons = substr_count($firstLine, ';');
        $commas = substr_count($firstLine, ',');
        return $semicolons >= $commas ? ';' : ',';
    }

    /**
     * Accepts "TT.MM.JJJJ", "TT.MM." / "TT.MM" (year unknown) and ISO
     * "JJJJ-MM-TT".
     *
     * @return array{day: int, month: int, year: ?int}|null
     */
    public static function parseGermanDate(string $raw): ?array {
        $raw = trim($raw);

        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $raw, $m) === 1) {
            [$day, $month, $year] = [(int)$m[1], (int)$m[2], (int)$m[3]];
            return checkdate($month, $day, $year) ? ['day' => $day, 'month' => $month, 'year' => $year] : null;
        }

        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.?$/', $raw, $m) === 1) {
            [$day, $month] = [(int)$m[1], (int)$m[2]];
            return checkdate($month, $day, 2000) ? ['day' => $day, 'month' => $month, 'year' => null] : null;
        }

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $raw, $m) === 1) {
            [$year, $month, $day] = [(int)$m[1], (int)$m[2], (int)$m[3]];
            return checkdate($month, $day, $year) ? ['day' => $day, 'month' => $month, 'year' => $year] : null;
        }

        return null;
    }
}
