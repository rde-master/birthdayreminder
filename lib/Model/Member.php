<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Model;

/**
 * Value object for one contact read from the club address book.
 */
final class Member {
    public function __construct(
        public readonly string $uid,
        public readonly string $displayName,
        public readonly ?string $email,
        public readonly int $month,
        public readonly int $day,
        public readonly ?int $year,
    ) {
    }

    public function hasKnownYear(): bool {
        return $this->year !== null;
    }
}
