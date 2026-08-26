<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Recipient>
 */
class RecipientMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'birthdayreminder_recipient', Recipient::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id): Recipient {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));
        return $this->findEntity($qb);
    }

    /**
     * @return Recipient[]
     */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName());
        return $this->findEntities($qb);
    }

    public function findByTypeAndValue(string $type, string $value): ?Recipient {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('recipient_type', $qb->createNamedParameter($type)))
            ->andWhere($qb->expr()->eq('recipient_value', $qb->createNamedParameter($value)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    public function findOrCreate(string $type, string $value): Recipient {
        $existing = $this->findByTypeAndValue($type, $value);
        if ($existing !== null) {
            return $existing;
        }
        $recipient = new Recipient();
        $recipient->setRecipientType($type);
        $recipient->setRecipientValue($value);
        $recipient->setOnlyMilestones(false);
        $recipient->setCreatedAt(time());
        return $this->insert($recipient);
    }
}
