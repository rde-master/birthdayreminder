<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Contacts;

use OCA\BirthdayReminder\Model\Member;
use OCA\DAV\CardDAV\CardDavBackend;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Reader;

/**
 * Reads members (contacts with a BDAY) from a Nextcloud address book without
 * relying on a logged-in user session, which OCP\Contacts\IManager requires
 * and a background job does not have.
 */
final class ContactsGateway {
    public function __construct(
        private CardDavBackend $backend,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<int, array{id: int, uri: string}> address books owned by the given user,
     *         keyed by book id, for the settings UI dropdown.
     */
    public function getAddressBooksForOwner(string $ownerUid): array {
        $books = $this->backend->getAddressBooksForUser('principals/users/' . $ownerUid);
        $result = [];
        foreach ($books as $book) {
            $result[(int)$book['id']] = [
                'id' => (int)$book['id'],
                'uri' => (string)$book['uri'],
                'displayName' => (string)($book['{DAV:}displayname'] ?? $book['uri']),
            ];
        }
        return $result;
    }

    /**
     * @return Member[]
     */
    public function getMembers(int $addressBookId): array {
        $members = [];
        foreach ($this->backend->getCards($addressBookId) as $row) {
            $member = $this->parseCard($row);
            if ($member !== null) {
                $members[] = $member;
            }
        }
        return $members;
    }

    /**
     * @param array{carddata: string, uri: string} $row
     */
    private function parseCard(array $row): ?Member {
        $vcard = Reader::read($row['carddata']);

        if (!isset($vcard->BDAY)) {
            return null;
        }

        $parsed = self::parseBirthday((string)$vcard->BDAY);
        $uid = isset($vcard->UID) ? (string)$vcard->UID : $row['uri'];

        if ($parsed === null) {
            $this->logger->warning('birthdayreminder: could not parse BDAY value', [
                'contactUid' => $uid,
                'rawValue' => (string)$vcard->BDAY,
            ]);
            return null;
        }

        $displayName = isset($vcard->FN) ? (string)$vcard->FN : $uid;
        $email = isset($vcard->EMAIL) ? (string)$vcard->EMAIL : null;

        return new Member(
            uid: $uid,
            displayName: $displayName,
            email: $email !== '' ? $email : null,
            month: $parsed['month'],
            day: $parsed['day'],
            year: $parsed['year'],
        );
    }

    /**
     * @return array{month: int, day: int, year: ?int}|null
     */
    public static function parseBirthday(string $raw): ?array {
        $raw = trim($raw);

        // RFC 6350 "no year known" form: --MMDD or --MM-DD
        if (preg_match('/^--(\d{2})-?(\d{2})$/', $raw, $m) === 1) {
            if (!checkdate((int)$m[1], (int)$m[2], 2000)) {
                return null;
            }
            return ['month' => (int)$m[1], 'day' => (int)$m[2], 'year' => null];
        }

        // Full date form: YYYYMMDD, YYYY-MM-DD, optionally with a time component.
        if (preg_match('/^(\d{4})-?(\d{2})-?(\d{2})/', $raw, $m) === 1) {
            $year = (int)$m[1];
            $month = (int)$m[2];
            $day = (int)$m[3];
            if (!checkdate($month, $day, $year)) {
                return null;
            }
            return ['month' => $month, 'day' => $day, 'year' => $year];
        }

        return null;
    }
}
