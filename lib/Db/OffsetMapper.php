<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Offset>
 */
class OffsetMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'birthdayreminder_offset', Offset::class);
    }

    /**
     * @return Offset[]
     */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName());
        return $this->findEntities($qb);
    }

    /**
     * @return Offset[]
     */
    public function findByRecipientId(int $recipientId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('recipient_id', $qb->createNamedParameter($recipientId)));
        return $this->findEntities($qb);
    }

    public function deleteByRecipientId(int $recipientId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('recipient_id', $qb->createNamedParameter($recipientId)));
        $qb->executeStatement();
    }

    public function add(int $recipientId, int $daysBefore): Offset {
        $offset = new Offset();
        $offset->setRecipientId($recipientId);
        $offset->setDaysBefore($daysBefore);
        return $this->insert($offset);
    }
}
