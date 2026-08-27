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
        $today = new DateTimeImmutable('today');
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
        $today = new DateTimeImmutable('today');
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
     * themselves. Used by the scheduled background job.
     */
    public function run(?DateTimeImmutable $today = null): void {
        $today ??= new DateTimeImmutable('today');
        $this->runReminders($today);
        $this->runCongrats($today);
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
        if (!$this->configService->getRemindersEnabled()) {
            $this->logger->info('birthdayreminder: reminder mails are disabled, skipping run');
            return false;
        }

        $today ??= new DateTimeImmutable('today');
        $context = $this->buildContext($today);

        foreach ($context['recipients'] as $recipient) {
            $this->sendRemindersForRecipient(
                $recipient,
                $context['offsetsByRecipient'][$recipient->getId()] ?? [],
                $context['matchesByOffset'],
                $context['milestoneAges']
            );
        }

        return true;
    }

    /**
     * Only the congratulation mails to today's members - used by the "jetzt
     * versenden" button in the admin UI, and internally by run().
     *
     * @return bool false if skipped because congrats mails are globally disabled
     */
    public function runCongrats(?DateTimeImmutable $today = null): bool {
        if (!$this->configService->getCongratsEnabled()) {
            $this->logger->info('birthdayreminder: congrats mails are disabled, skipping run');
            return false;
        }

        $today ??= new DateTimeImmutable('today');
        $context = $this->buildContext($today);
        $this->sendCongratulations($context['matchesByOffset'][0] ?? []);
        return true;
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
     */
    private function sendRemindersForRecipient(
        Recipient $recipient,
        array $offsets,
        array $matchesByOffset,
        array $milestoneAges,
    ): void {
        $emails = $this->recipientResolver->resolveEmails($recipient);
        if (empty($emails)) {
            return;
        }

        foreach ($offsets as $daysBefore) {
            foreach ($matchesByOffset[$daysBefore] ?? [] as $match) {
                if ($recipient->getOnlyMilestones() && !$this->calculator->isMilestoneAge($match['age'], $milestoneAges)) {
                    continue;
                }

                $member = $match['member'];
                $targetYear = (int)$match['targetDate']->format('Y');

                if ($this->reminderLogMapper->alreadySent($member->uid, ReminderLog::TYPE_OFFSET, $daysBefore, $targetYear)) {
                    continue;
                }

                $giftText = $match['age'] !== null
                    ? $this->milestoneMapper->findByAge($match['age'])?->getGiftText()
                    : null;

                // Only mark as sent if every recipient address for this match succeeded;
                // a partial failure retries for everyone on the next run rather than
                // silently and permanently dropping the reminder for the failed address.
                $allSucceeded = true;
                foreach ($emails as $email) {
                    if (!$this->mailService->sendReminder($email, $member, $daysBefore, $match['targetDate'], $match['age'], $giftText)) {
                        $allSucceeded = false;
                        $this->logger->error('birthdayreminder: reminder mail delivery failed', [
                            'contactUid' => $member->uid,
                            'toEmail' => $email,
                            'daysBefore' => $daysBefore,
                        ]);
                    }
                }
                if ($allSucceeded) {
                    $this->reminderLogMapper->logSent($member->uid, ReminderLog::TYPE_OFFSET, $daysBefore, $targetYear);
                }
            }
        }
    }

    /**
     * @param array<int, array{member: Member, daysBefore: int, targetDate: DateTimeImmutable, age: ?int}> $todaysMatches
     */
    private function sendCongratulations(array $todaysMatches): void {
        foreach ($todaysMatches as $match) {
            $member = $match['member'];

            if ($member->email === null) {
                $this->logger->info('birthdayreminder: member has no e-mail address, skipping congrats mail', [
                    'contactUid' => $member->uid,
                ]);
                continue;
            }

            $targetYear = (int)$match['targetDate']->format('Y');
            if ($this->reminderLogMapper->alreadySent($member->uid, ReminderLog::TYPE_CONGRATS, ReminderLog::NO_OFFSET, $targetYear)) {
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
                $this->reminderLogMapper->logSent($member->uid, ReminderLog::TYPE_CONGRATS, ReminderLog::NO_OFFSET, $targetYear);
            } else {
                $this->logger->error('birthdayreminder: congrats mail delivery failed', ['contactUid' => $member->uid]);
            }
        }
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
