<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Model;

/**
 * Value object for one club member, computed from the app's own member
 * registry (lib/Db/Member.php + MemberMapper).
 */
final class Member {
    public function __construct(
        public readonly string $uid,
        public readonly string $displayName,
        public readonly ?string $email,
        public readonly int $month,
        public readonly int $day,
        public readonly ?int $year,
        public readonly ?string $firstName = null,
    ) {
    }

    public function hasKnownYear(): bool {
        return $this->year !== null;
    }

    /**
     * The name to use for a "Hallo {vorname}"-style greeting: the real
     * first name when known, otherwise the first word of the display name.
     */
    public function greetingName(): string {
        if ($this->firstName !== null && $this->firstName !== '') {
            return $this->firstName;
        }
        $parts = preg_split('/\s+/', trim($this->displayName));
        return $parts[0] !== '' ? $parts[0] : $this->displayName;
    }
}
