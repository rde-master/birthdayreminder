<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Service;

use DateTimeImmutable;
use OCA\BirthdayReminder\Model\Member;

/**
 * Pure date-matching logic - no I/O, no Nextcloud runtime dependency, unit-testable.
 */
final class ReminderCalculator {
    /**
     * For every member/offset combination, checks whether "today + daysBefore"
     * lands on that member's birthday. Handles year-boundary wraparound naturally
     * because the comparison is month/day only, and normalizes Feb 29 birthdays
     * to Feb 28 in non-leap target years.
     *
     * @param Member[] $members
     * @param int[] $offsets distinct days-before values to check, e.g. [30, 14, 2, 1, 0]
     * @return array<int, array{member: Member, daysBefore: int, targetDate: DateTimeImmutable, age: ?int}>
     */
    public function findMatches(array $members, array $offsets, DateTimeImmutable $today): array {
        $matches = [];
        foreach ($offsets as $daysBefore) {
            $target = $today->modify(sprintf('+%d days', $daysBefore));
            $targetMonth = (int)$target->format('n');
            $targetDay = (int)$target->format('j');
            $targetYear = (int)$target->format('Y');

            foreach ($members as $member) {
                [$normMonth, $normDay] = $this->normalizedBirthday($member, $targetYear);
                if ($normMonth === $targetMonth && $normDay === $targetDay) {
                    $matches[] = [
                        'member' => $member,
                        'daysBefore' => $daysBefore,
                        'targetDate' => $target,
                        'age' => $member->hasKnownYear() ? $targetYear - $member->year : null,
                    ];
                }
            }
        }
        return $matches;
    }

    /**
     * @return array{0: int, 1: int} [month, day]
     */
    private function normalizedBirthday(Member $member, int $targetYear): array {
        if ($member->month === 2 && $member->day === 29 && !checkdate(2, 29, $targetYear)) {
            return [2, 28];
        }
        return [$member->month, $member->day];
    }

    /**
     * @param int[] $milestoneAges
     */
    public function isMilestoneAge(?int $age, array $milestoneAges): bool {
        return $age !== null && in_array($age, $milestoneAges, true);
    }

    /**
     * Days until the member's next birthday (0 = today), used for the
     * dashboard widget's "upcoming birthdays" list. Checks this year first,
     * then next year, so it also works right after a birthday just passed.
     *
     * @return array{daysUntil: int, targetDate: DateTimeImmutable, age: ?int}
     */
    public function daysUntilNextBirthday(Member $member, DateTimeImmutable $today): array {
        $todayYear = (int)$today->format('Y');
        foreach ([$todayYear, $todayYear + 1] as $year) {
            [$month, $day] = $this->normalizedBirthday($member, $year);
            $candidate = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
            if ($candidate >= $today) {
                return [
                    'daysUntil' => (int)$today->diff($candidate)->days,
                    'targetDate' => $candidate,
                    'age' => $member->hasKnownYear() ? $year - $member->year : null,
                ];
            }
        }
        // Unreachable: the next-year candidate is always >= today.
        throw new \LogicException('could not determine next birthday');
    }
}
