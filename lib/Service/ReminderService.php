<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Service;

use DateTimeImmutable;
use OCA\BirthdayReminder\Db\Member as MemberEntity;
use OCA\BirthdayReminder\Db\MemberMapper;
use OCA\BirthdayReminder\Db\MilestoneMapper;
use OCA\BirthdayReminder\Db\OffsetMapper;
use OCA\BirthdayReminder\Db\Recipient;
use OCA\BirthdayReminder\Db\RecipientMapper;
use OCA\BirthdayReminder\Db\ReminderLog;
use OCA\BirthdayReminder\Db\ReminderLogMapper;
use OCA\BirthdayReminder\Model\Member;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates one daily run: read active members, compute matches, resolve
 * recipients, send mail, log for idempotency.
 */
final class ReminderService {
    public function __construct(
        private MemberMapper $memberMapper,
        private ReminderCalculator $calculator,
        private RecipientMapper $recipientMapper,
        private OffsetMapper $offsetMapper,
        private MilestoneMapper $milestoneMapper,
        private ReminderLogMapper $reminderLogMapper,
        private RecipientResolver $recipientResolver,
        private MailService $mailService,
        private MailTemplateRenderer $templateRenderer,
        private ConfigService $configService,
        private ClockService $clockService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Upcoming birthdays sorted by proximity, for the Dashboard widget.
     * Shares the same member-reading and date logic as run() so the widget
     * can never drift out of sync with what the daily job actually matches.
     *
     * @return array<int, array{member: Member, daysUntil: int, targetDate: DateTimeImmutable, age: ?int}>
     */
    public function getUpcomingBirthdays(int $limit = 7): array {
        $today = $this->clockService->today();
        $members = $this->activeMembers();

        $upcoming = array_map(
            fn (Member $member) => array_merge(['member' => $member], $this->calculator->daysUntilNextBirthday($member, $today)),
            $members
        );

        usort($upcoming, static fn ($a, $b) => $a['daysUntil'] <=> $b['daysUntil']);

        return array_slice($upcoming, 0, $limit);
    }

    /**
     * All active members whose next birthday falls within the given number
     * of days from today (inclusive), sorted by proximity. Used by the
     * "Übersicht" page's today/7-day/30-day columns - shares the same date
     * logic as getUpcomingBirthdays()/run() so it can never drift out of sync.
     *
     * @return array<int, array{member: Member, daysUntil: int, targetDate: DateTimeImmutable, age: ?int}>
     */
    public function getUpcomingBirthdaysWithinDays(int $maxDays): array {
        $today = $this->clockService->today();
        $members = $this->activeMembers();

        $upcoming = array_filter(
            array_map(
                fn (Member $member) => array_merge(['member' => $member], $this->calculator->daysUntilNextBirthday($member, $today)),
                $members
            ),
            static fn ($entry) => $entry['daysUntil'] <= $maxDays
        );

        usort($upcoming, static fn ($a, $b) => $a['daysUntil'] <=> $b['daysUntil']);

        return array_values($upcoming);
    }

    /**
     * Full daily pass: reminders to recipients + congrats to the members
     * themselves. Used by the scheduled background job. Logs a TYPE_NONE
     * marker if the whole run found nothing to send, so "checked, nothing
     * due today" is distinguishable from "the job never ran" in the log.
     */
    public function run(?DateTimeImmutable $today = null): void {
        $today ??= $this->clockService->today();
        $remindersSent = $this->doRunReminders($today);
        $congratsSent = $this->doRunCongrats($today);

        if (($remindersSent ?? 0) + ($congratsSent ?? 0) === 0) {
            $this->reminderLogMapper->logNoMailSent($today);
        }
    }

    /**
     * Only the reminder mails to recipients - used by the "jetzt versenden"
     * button in the admin UI, and internally by run(). Shares the exact
     * same idempotency log as the scheduled job, so a manual trigger never
     * causes a duplicate send later that day (or vice versa).
     *
     * @return bool false if skipped because reminder mails are globally disabled
     */
    public function runReminders(?DateTimeImmutable $today = null): bool {
        return $this->doRunReminders($today ?? $this->clockService->today()) !== null;
    }

    /**
     * Only the congratulation mails to today's members - used by the "jetzt
     * versenden" button in the admin UI, and internally by run().
     *
     * @return bool false if skipped because congrats mails are globally disabled
     */
    public function runCongrats(?DateTimeImmutable $today = null): bool {
        return $this->doRunCongrats($today ?? $this->clockService->today()) !== null;
    }

    /**
     * @return int|null number of reminder e-mails actually sent, or null if
     *                   skipped because reminder mails are globally disabled
     */
    private function doRunReminders(DateTimeImmutable $today): ?int {
        if (!$this->configService->getRemindersEnabled()) {
            $this->logger->info('birthdayreminder: reminder mails are disabled, skipping run');
            return null;
        }

        $context = $this->buildContext($today);

        $sentCount = 0;
        foreach ($context['recipients'] as $recipient) {
            $sentCount += $this->sendRemindersForRecipient(
                $recipient,
                $context['offsetsByRecipient'][$recipient->getId()] ?? [],
                $context['matchesByOffset'],
                $context['milestoneAges']
            );
        }

        return $sentCount;
    }

    /**
     * @return int|null number of congrats e-mails actually sent, or null if
     *                   skipped because congrats mails are globally disabled
     */
    private function doRunCongrats(DateTimeImmutable $today): ?int {
        if (!$this->configService->getCongratsEnabled()) {
            $this->logger->info('birthdayreminder: congrats mails are disabled, skipping run');
            return null;
        }

        $context = $this->buildContext($today);
        return $this->sendCongratulations($context['matchesByOffset'][0] ?? []);
    }

    /**
     * @return array{
     *     recipients: Recipient[],
     *     offsetsByRecipient: array<int, int[]>,
     *     matchesByOffset: array<int, array<int, array{member: Member, daysBefore: int, targetDate: DateTimeImmutable, age: ?int}>>,
     *     milestoneAges: int[],
     * }
     */
    private function buildContext(DateTimeImmutable $today): array {
        $members = $this->activeMembers();
        $recipients = $this->recipientMapper->findAll();
        $milestoneAges = $this->milestoneMapper->findAllAges();

        $offsetsInUse = [0]; // 0 is always evaluated: it drives the congrats mail.
        $offsetsByRecipient = [];
        foreach ($recipients as $recipient) {
            $offsets = array_map(
                static fn ($o) => $o->getDaysBefore(),
                $this->offsetMapper->findByRecipientId($recipient->getId())
            );
            $offsetsByRecipient[$recipient->getId()] = $offsets;
            foreach ($offsets as $daysBefore) {
                $offsetsInUse[$daysBefore] = $daysBefore;
            }
        }

        $matches = $this->calculator->findMatches($members, array_values($offsetsInUse), $today);
        $matchesByOffset = [];
        foreach ($matches as $match) {
            $matchesByOffset[$match['daysBefore']][] = $match;
        }

        return [
            'recipients' => $recipients,
            'offsetsByRecipient' => $offsetsByRecipient,
            'matchesByOffset' => $matchesByOffset,
            'milestoneAges' => $milestoneAges,
        ];
    }

    /**
     * @param int[] $offsets
     * @param array<int, array<int, array{member: Member, daysBefore: int, targetDate: DateTimeImmutable, age: ?int}>> $matchesByOffset
     * @param int[] $milestoneAges
     * @return int number of reminder e-mails actually sent for this recipient
     */
    private function sendRemindersForRecipient(
        Recipient $recipient,
        array $offsets,
        array $matchesByOffset,
        array $milestoneAges,
    ): int {
        $emails = $this->recipientResolver->resolveEmails($recipient);
        if (empty($emails)) {
            return 0;
        }

        $sentCount = 0;
        foreach ($offsets as $daysBefore) {
            foreach ($matchesByOffset[$daysBefore] ?? [] as $match) {
                if ($recipient->getOnlyMilestones() && !$this->calculator->isMilestoneAge($match['age'], $milestoneAges)) {
                    continue;
                }

                $member = $match['member'];
                $targetYear = (int)$match['targetDate']->format('Y');
                $giftText = $match['age'] !== null
                    ? $this->milestoneMapper->findByAge($match['age'])?->getGiftText()
                    : null;

                // Idempotency and logging are per (match, recipient address), not per
                // match alone - otherwise the first recipient to receive this match
                // would mark it "sent" and every other recipient subscribed to the
                // same offset would be silently skipped.
                foreach ($emails as $email) {
                    if ($this->reminderLogMapper->alreadySent($member->uid, ReminderLog::TYPE_OFFSET, $daysBefore, $targetYear, $email)) {
                        continue;
                    }

                    if ($this->mailService->sendReminder($email, $member, $daysBefore, $match['targetDate'], $match['age'], $giftText, $recipient->getBirthdateInSubject())) {
                        $this->reminderLogMapper->logSent($member->uid, ReminderLog::TYPE_OFFSET, $daysBefore, $targetYear, $email);
                        $sentCount++;
                    } else {
                        $this->logger->error('birthdayreminder: reminder mail delivery failed', [
                            'contactUid' => $member->uid,
                            'toEmail' => $email,
                            'daysBefore' => $daysBefore,
                        ]);
                    }
                }
            }
        }
        return $sentCount;
    }

    /**
     * @param array<int, array{member: Member, daysBefore: int, targetDate: DateTimeImmutable, age: ?int}> $todaysMatches
     * @return int number of congrats e-mails actually sent
     */
    private function sendCongratulations(array $todaysMatches): int {
        $sentCount = 0;
        foreach ($todaysMatches as $match) {
            $member = $match['member'];

            if ($member->email === null) {
                $this->logger->info('birthdayreminder: member has no e-mail address, skipping congrats mail', [
                    'contactUid' => $member->uid,
                ]);
                continue;
            }

            $targetYear = (int)$match['targetDate']->format('Y');
            if ($this->reminderLogMapper->alreadySent($member->uid, ReminderLog::TYPE_CONGRATS, ReminderLog::NO_OFFSET, $targetYear, $member->email)) {
                continue;
            }

            $placeholders = [
                'name' => $member->displayName,
                'vorname' => $member->greetingName(),
                'alter' => $match['age'] !== null ? (string)$match['age'] : '',
                'datum' => $match['targetDate']->format('d.m.Y'),
                'wochentag' => GermanDate::weekdayName($match['targetDate']),
            ];

            $subject = $this->templateRenderer->render($this->configService->getCongratsSubjectTemplate(), $placeholders);
            $body = $this->templateRenderer->render($this->configService->getCongratsBodyTemplate(), $placeholders);

            $succeeded = $this->mailService->sendCongratulation($member->email, $subject, $body);
            if ($succeeded) {
                $this->reminderLogMapper->logSent($member->uid, ReminderLog::TYPE_CONGRATS, ReminderLog::NO_OFFSET, $targetYear, $member->email);
                $sentCount++;
            } else {
                $this->logger->error('birthdayreminder: congrats mail delivery failed', ['contactUid' => $member->uid]);
            }
        }
        return $sentCount;
    }

    /**
     * @return Member[]
     */
    private function activeMembers(): array {
        return array_map(self::toModelMember(...), $this->memberMapper->findAllActive());
    }

    private static function toModelMember(MemberEntity $entity): Member {
        return new Member(
            uid: (string)$entity->getId(),
            displayName: $entity->getDisplayName(),
            email: $entity->getEmail(),
            month: $entity->getBirthMonth(),
            day: $entity->getBirthDay(),
            year: $entity->getBirthYear(),
            firstName: $entity->getFirstName(),
        );
    }
}
