<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Db;

use DateTimeImmutable;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ReminderLog>
 */
class ReminderLogMapper extends QBMapper {
    /**
     * Prefix for the synthetic contact_uid of TYPE_NONE marker rows, so they
     * can never collide with a real member's numeric id and are trivially
     * recognisable in a raw DB dump. The current date is appended (not just
     * the year, unlike real reminder rows) since a marker's whole purpose is
     * "checked on this calendar day, nothing due" - one per day, every day.
     */
    private const NO_MAIL_MARKER_PREFIX = 'no-mail:';

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'birthdayreminder_log', ReminderLog::class);
    }

    /**
     * Idempotency check: has this exact reminder already been sent to this
     * recipient address? Pass ReminderLog::NO_OFFSET for $daysBefore on
     * congrats-type entries.
     */
    public function alreadySent(string $contactUid, string $reminderType, int $daysBefore, int $birthdayYear, string $recipientEmail): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from($this->getTableName())
            ->where($qb->expr()->eq('contact_uid', $qb->createNamedParameter($contactUid)))
            ->andWhere($qb->expr()->eq('reminder_type', $qb->createNamedParameter($reminderType)))
            ->andWhere($qb->expr()->eq('birthday_year', $qb->createNamedParameter($birthdayYear, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->eq('days_before', $qb->createNamedParameter($daysBefore, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->eq('recipient_email', $qb->createNamedParameter($recipientEmail)));

        return count($qb->executeQuery()->fetchAll()) > 0;
    }

    /**
     * @return ReminderLog[] most recent sends first
     */
    public function findRecent(int $limit = 200): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->orderBy('sent_at', 'DESC')
            ->setMaxResults($limit);
        return $this->findEntities($qb);
    }

    /**
     * Wipes the entire send history. Note: this also resets idempotency -
     * anything already sent today would be eligible to send again on the
     * next run. Callers should warn about that before invoking this.
     *
     * @return int number of rows deleted
     */
    public function deleteAll(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName());
        return $qb->executeStatement();
    }

    public function logSent(string $contactUid, string $reminderType, int $daysBefore, int $birthdayYear, string $recipientEmail): ReminderLog {
        $log = new ReminderLog();
        $log->setContactUid($contactUid);
        $log->setReminderType($reminderType);
        $log->setDaysBefore($daysBefore);
        $log->setBirthdayYear($birthdayYear);
        $log->setRecipientEmail($recipientEmail);
        $log->setSentAt(time());
        try {
            return $this->insert($log);
        } catch (\OCP\DB\Exception $e) {
            // Unique-index race: another (near-)simultaneous job run already logged this send.
            // Treat as already-sent rather than failing the whole batch.
            if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                return $log;
            }
            throw $e;
        }
    }

    /**
     * Records that a completed run() found nothing to send at all (no
     * reminder offset matched, no birthday today) - written once per
     * calendar day so the admin can see the automatic check actually ran
     * rather than silently having stopped firing. Not itself gated on
     * ScheduleGate/last_run_date: run() already ensures this is only called
     * once per day (see DailyReminderJob/CronTriggerController), and a
     * same-day duplicate call is harmless - it hits the unique index and is
     * dropped exactly like a duplicate logSent() race.
     */
    public function logNoMailSent(DateTimeImmutable $today): void {
        $log = new ReminderLog();
        $log->setContactUid(self::NO_MAIL_MARKER_PREFIX . $today->format('Y-m-d'));
        $log->setReminderType(ReminderLog::TYPE_NONE);
        $log->setDaysBefore(ReminderLog::NO_OFFSET);
        $log->setBirthdayYear((int)$today->format('Y'));
        $log->setRecipientEmail(ReminderLog::NO_RECIPIENT);
        $log->setSentAt(time());
        try {
            $this->insert($log);
        } catch (\OCP\DB\Exception $e) {
            if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw $e;
            }
        }
    }
}
