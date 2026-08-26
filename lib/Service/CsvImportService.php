<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Service;

use OCA\BirthdayReminder\Db\Member;
use OCA\BirthdayReminder\Db\MemberMapper;

/**
 * Orchestrates a CSV import: parse -> validate rows -> diff against the
 * current member registry -> apply the plan via MemberMapper.
 */
final class CsvImportService {
    public function __construct(
        private CsvParser $csvParser,
        private MemberSyncPlanner $planner,
        private MemberMapper $memberMapper,
    ) {
    }

    /**
     * @param array{firstName: string, lastName: string, birthdate: string, email: string} $columnMapping
     *        Target field => CSV column header name. `email` may be an empty
     *        string if the CSV has no e-mail column.
     * @return array{inserted: int, updated: int, unchanged: int, disabled: int, errors: list<string>}
     */
    public function import(string $csvContent, string $delimiter, array $columnMapping): array {
        $rows = $this->csvParser->parseRows($csvContent, $delimiter);
        if (empty($rows)) {
            return ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'disabled' => 0, 'errors' => ['Die CSV-Datei ist leer.']];
        }

        $header = array_map('trim', $rows[0]);
        $columnIndex = static function (string $columnName) use ($header): ?int {
            $index = array_search($columnName, $header, true);
            return $index === false ? null : $index;
        };

        $firstNameCol = $columnIndex($columnMapping['firstName']);
        $lastNameCol = $columnIndex($columnMapping['lastName']);
        $birthdateCol = $columnIndex($columnMapping['birthdate']);
        $emailCol = $columnMapping['email'] !== '' ? $columnIndex($columnMapping['email']) : null;

        $parsedRows = [];
        $errors = [];

        foreach (array_slice($rows, 1) as $i => $row) {
            $lineNumber = $i + 2; // +1 for header, +1 for 1-based line numbers
            if (count(array_filter($row, static fn ($v) => trim((string)$v) !== '')) === 0) {
                continue; // blank line
            }

            $firstName = trim($row[$firstNameCol] ?? '');
            $lastName = trim($row[$lastNameCol] ?? '');
            if ($firstName === '' || $lastName === '') {
                $errors[] = "Zeile {$lineNumber}: Vorname oder Nachname fehlt, Zeile übersprungen.";
                continue;
            }

            $birthdateRaw = trim($row[$birthdateCol] ?? '');
            $parsedDate = CsvParser::parseGermanDate($birthdateRaw);
            if ($parsedDate === null) {
                $errors[] = "Zeile {$lineNumber} ({$firstName} {$lastName}): Geburtsdatum \"{$birthdateRaw}\" konnte nicht gelesen werden, Zeile übersprungen.";
                continue;
            }

            $email = $emailCol !== null ? trim($row[$emailCol] ?? '') : '';

            $parsedRows[] = [
                'firstName' => $firstName,
                'lastName' => $lastName,
                'birthDay' => $parsedDate['day'],
                'birthMonth' => $parsedDate['month'],
                'birthYear' => $parsedDate['year'],
                'email' => $email !== '' ? $email : null,
            ];
        }

        $existingMembers = array_map(self::entityToPlanArray(...), $this->memberMapper->findAll());
        $plan = $this->planner->plan($existingMembers, $parsedRows);

        $now = time();

        foreach ($plan['inserts'] as $insert) {
            $member = new Member();
            $member->setFirstName($insert['firstName']);
            $member->setLastName($insert['lastName']);
            $member->setBirthDay($insert['birthDay']);
            $member->setBirthMonth($insert['birthMonth']);
            $member->setBirthYear($insert['birthYear']);
            $member->setEmail($insert['email']);
            $member->setDisabled(false);
            $member->setRemark(null);
            $member->setCreatedAt($now);
            $member->setUpdatedAt($now);
            $this->memberMapper->insert($member);
        }

        foreach ($plan['updates'] as $update) {
            $member = $this->memberMapper->find($update['id']);
            $member->setFirstName($update['firstName']);
            $member->setLastName($update['lastName']);
            $member->setBirthDay($update['birthDay']);
            $member->setBirthMonth($update['birthMonth']);
            $member->setBirthYear($update['birthYear']);
            $member->setEmail($update['email']);
            $member->setUpdatedAt($now);
            $this->memberMapper->update($member);
        }

        foreach ($plan['disables'] as $disable) {
            $member = $this->memberMapper->find($disable['id']);
            $member->setDisabled(true);
            $member->setRemark($disable['remark']);
            $member->setUpdatedAt($now);
            $this->memberMapper->update($member);
        }

        return [
            'inserted' => count($plan['inserts']),
            'updated' => count($plan['updates']),
            'unchanged' => $plan['unchangedCount'],
            'disabled' => count($plan['disables']),
            'errors' => $errors,
        ];
    }

    private static function entityToPlanArray(Member $member): array {
        return [
            'id' => $member->getId(),
            'firstName' => $member->getFirstName(),
            'lastName' => $member->getLastName(),
            'birthDay' => $member->getBirthDay(),
            'birthMonth' => $member->getBirthMonth(),
            'birthYear' => $member->getBirthYear(),
            'email' => $member->getEmail(),
            'disabled' => $member->getDisabled(),
            'remark' => $member->getRemark(),
        ];
    }
}
