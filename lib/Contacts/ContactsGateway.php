<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Contacts;

use OCA\BirthdayReminder\Db\Member;
use OCA\BirthdayReminder\Service\VCardDate;
use OCP\Constants;
use OCP\Contacts\IManager;
use OCP\IAddressBook;

/**
 * Bridges the app's own member registry with the current, logged-in user's
 * personal Nextcloud contacts, via the public OCP\Contacts\IManager API.
 * Only usable within a real request (it resolves the current user's own
 * address books) - unlike the old, since-removed ContactsGateway that used
 * CardDavBackend directly to work from a background job.
 */
final class ContactsGateway {
    public function __construct(
        private IManager $contactsManager,
    ) {
    }

    /**
     * @return array{rows: list<array{firstName: string, lastName: string, birthDay: int, birthMonth: int, birthYear: ?int, email: ?string}>, errors: list<string>}
     */
    public function importFromUserContacts(): array {
        $rows = [];
        $errors = [];

        foreach ($this->contactsManager->getUserAddressBooks() as $addressBook) {
            if ($addressBook->isSystemAddressBook()) {
                continue;
            }

            foreach ($addressBook->search('', ['FN', 'N', 'BDAY', 'EMAIL'], []) as $contact) {
                $name = $this->extractName($contact);
                if ($name === null) {
                    $label = is_string($contact['FN'] ?? null) ? $contact['FN'] : '?';
                    $errors[] = "Kontakt \"{$label}\" hat keinen in Vor-/Nachname aufteilbaren Namen, übersprungen.";
                    continue;
                }
                [$firstName, $lastName] = $name;

                $bday = is_string($contact['BDAY'] ?? null) ? VCardDate::parse($contact['BDAY']) : null;
                if ($bday === null) {
                    $errors[] = "Kontakt \"{$firstName} {$lastName}\" hat kein (lesbares) Geburtsdatum, übersprungen.";
                    continue;
                }

                $rows[] = [
                    'firstName' => $firstName,
                    'lastName' => $lastName,
                    'birthDay' => $bday['day'],
                    'birthMonth' => $bday['month'],
                    'birthYear' => $bday['year'],
                    'email' => $this->firstEmail($contact['EMAIL'] ?? null),
                ];
            }
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * @param Member[] $members
     * @return array{created: int, updated: int, addressBookName: string}
     */
    public function exportMembersToUserContacts(array $members): array {
        $target = $this->findExportTarget();
        if ($target === null) {
            throw new \RuntimeException('Kein beschreibbares persönliches Adressbuch gefunden. Lege in den Kontakten zuerst ein eigenes Adressbuch an.');
        }

        // E-mail is the primary match key (same reasoning as the CSV/Contacts
        // import's MemberSyncPlanner) - it's what keeps repeated exports from
        // creating duplicate contacts even if a member's name changed since
        // the last export. Falls back to full name only for contacts/members
        // without an e-mail address.
        $existingUriByEmail = [];
        $existingUriByName = [];
        foreach ($target->search('', ['FN', 'EMAIL'], []) as $existing) {
            if (!is_string($existing['URI'] ?? null)) {
                continue;
            }
            $email = $this->firstEmail($existing['EMAIL'] ?? null);
            if ($email !== null && !isset($existingUriByEmail[mb_strtolower($email)])) {
                $existingUriByEmail[mb_strtolower($email)] = $existing['URI'];
            }
            if (is_string($existing['FN'] ?? null)) {
                $existingUriByName[mb_strtolower(trim($existing['FN']))] = $existing['URI'];
            }
        }

        $created = 0;
        $updated = 0;

        foreach ($members as $member) {
            $fn = trim($member->getFirstName() . ' ' . $member->getLastName());
            $properties = [
                'FN' => $fn,
                'BDAY' => VCardDate::format($member->getBirthDay(), $member->getBirthMonth(), $member->getBirthYear()),
            ];
            if ($member->getEmail() !== null) {
                $properties['EMAIL'] = $member->getEmail();
            }

            $existingUri = $member->getEmail() !== null
                ? ($existingUriByEmail[mb_strtolower($member->getEmail())] ?? $existingUriByName[mb_strtolower($fn)] ?? null)
                : ($existingUriByName[mb_strtolower($fn)] ?? null);

            if ($existingUri !== null) {
                $properties['URI'] = $existingUri;
                $updated++;
            } else {
                $created++;
            }

            $this->contactsManager->createOrUpdate($properties, $target->getKey());
        }

        return ['created' => $created, 'updated' => $updated, 'addressBookName' => (string)$target->getDisplayName()];
    }

    private function findExportTarget(): ?IAddressBook {
        foreach ($this->contactsManager->getUserAddressBooks() as $addressBook) {
            if ($addressBook->isSystemAddressBook() || $addressBook->isShared()) {
                continue;
            }
            if (($addressBook->getPermissions() & Constants::PERMISSION_CREATE) !== 0) {
                return $addressBook;
            }
        }
        return null;
    }

    /**
     * N (structured "Nachname;Vorname;;;") wins when present and both parts
     * are non-empty; otherwise falls back to splitting FN on its last space.
     * A single-word FN can't be reliably split into first+last, so that
     * yields null (row skipped by the caller) rather than a guess.
     *
     * @return array{0: string, 1: string}|null [firstName, lastName]
     */
    private function extractName(array $contact): ?array {
        $n = $contact['N'] ?? null;
        if (is_string($n) && $n !== '') {
            $parts = explode(';', $n);
            $lastName = trim($parts[0] ?? '');
            $firstName = trim($parts[1] ?? '');
            if ($firstName !== '' && $lastName !== '') {
                return [$firstName, $lastName];
            }
        }

        $fn = trim((string)($contact['FN'] ?? ''));
        if ($fn === '') {
            return null;
        }
        $words = preg_split('/\s+/', $fn);
        if (count($words) < 2) {
            return null;
        }
        $lastName = array_pop($words);
        $firstName = implode(' ', $words);
        return [$firstName, $lastName];
    }

    private function firstEmail(mixed $email): ?string {
        if (is_array($email)) {
            $email = $email[0] ?? null;
        }
        if (!is_string($email) || trim($email) === '') {
            return null;
        }
        return trim($email);
    }
}
