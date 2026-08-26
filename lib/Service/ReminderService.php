<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Service;

use DateTimeImmutable;
use OCA\BirthdayReminder\Contacts\ContactsGateway;
use OCA\BirthdayReminder\Db\MilestoneMapper;
use OCA\BirthdayReminder\Db\OffsetMapper;
use OCA\BirthdayReminder\Db\Recipient;
use OCA\BirthdayReminder\Db\RecipientMapper;
use OCA\BirthdayReminder\Db\ReminderLog;
use OCA\BirthdayReminder\Db\ReminderLogMapper;
use OCA\BirthdayReminder\Model\Member;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates one daily run: read contacts, compute matches, resolve
 * recipients, send mail, log for idempotency.
 */
final class ReminderService {
    public function __construct(
        private ContactsGateway $contactsGateway,
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
     * Shares the same contact-reading and date logic as run() so the widget
     * can never drift out of sync with what the daily job actually matches.
     *
     * @return array<int, array{member: Member, daysUntil: int, targetDate: DateTimeImmutable, age: ?int}>
     */
    public function getUpcomingBirthdays(int $limit = 7): array {
        $addressBookId = $this->configService->getAddressBookId();
        if ($addressBookId === null) {
            return [];
        }

        $today = new DateTimeImmutable('today');
        $members = $this->contactsGateway->getMembers($addressBookId);

        $upcoming = array_map(
            fn (Member $member) => array_merge(['member' => $member], $this->calculator->daysUntilNextBirthday($member, $today)),
            $members
        );

        usort($upcoming, static fn ($a, $b) => $a['daysUntil'] <=> $b['daysUntil']);

        return array_slice($upcoming, 0, $limit);
    }

    public function run(?DateTimeImmutable $today = null): void {
        $addressBookId = $this->configService->getAddressBookId();
        if ($addressBookId === null) {
            $this->logger->warning('birthdayreminder: no address book configured, skipping run');
            return;
        }

        $today ??= new DateTimeImmutable('today');
        $members = $this->contactsGateway->getMembers($addressBookId);
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

        foreach ($recipients as $recipient) {
            $this->sendRemindersForRecipient(
                $recipient,
                $offsetsByRecipient[$recipient->getId()] ?? [],
                $matchesByOffset,
                $milestoneAges
            );
        }

        $this->sendCongratulations($matchesByOffset[0] ?? []);
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
                    if (!$this->mailService->sendReminder($email, $member, $daysBefore, $giftText)) {
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
                'vorname' => $this->firstName($member->displayName),
                'alter' => $match['age'] !== null ? (string)$match['age'] : '',
                'datum' => $match['targetDate']->format('d.m.Y'),
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

    private function firstName(string $displayName): string {
        $parts = preg_split('/\s+/', trim($displayName));
        return $parts[0] !== '' ? $parts[0] : $displayName;
    }
}
