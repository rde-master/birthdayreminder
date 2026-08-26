<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ReminderLog>
 */
class ReminderLogMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'birthdayreminder_log', ReminderLog::class);
    }

    /**
     * Idempotency check: has this exact reminder already been sent?
     * Pass ReminderLog::NO_OFFSET for $daysBefore on congrats-type entries.
     */
    public function alreadySent(string $contactUid, string $reminderType, int $daysBefore, int $birthdayYear): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from($this->getTableName())
            ->where($qb->expr()->eq('contact_uid', $qb->createNamedParameter($contactUid)))
            ->andWhere($qb->expr()->eq('reminder_type', $qb->createNamedParameter($reminderType)))
            ->andWhere($qb->expr()->eq('birthday_year', $qb->createNamedParameter($birthdayYear, \PDO::PARAM_INT)))
            ->andWhere($qb->expr()->eq('days_before', $qb->createNamedParameter($daysBefore, \PDO::PARAM_INT)));

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

    public function logSent(string $contactUid, string $reminderType, int $daysBefore, int $birthdayYear): ReminderLog {
        $log = new ReminderLog();
        $log->setContactUid($contactUid);
        $log->setReminderType($reminderType);
        $log->setDaysBefore($daysBefore);
        $log->setBirthdayYear($birthdayYear);
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
}
