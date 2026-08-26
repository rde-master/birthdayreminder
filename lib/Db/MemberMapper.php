<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Member>
 */
class MemberMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'birthdayreminder_member', Member::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id): Member {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));
        return $this->findEntity($qb);
    }

    /**
     * @return Member[]
     */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->orderBy('last_name', 'ASC')
            ->addOrderBy('first_name', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * @return Member[]
     */
    public function findAllActive(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('disabled', $qb->createNamedParameter(false, \PDO::PARAM_BOOL)));
        return $this->findEntities($qb);
    }

    /**
     * Case-insensitive match on first + last name - the natural key used by
     * the CSV import to decide insert vs. update.
     */
    public function findByName(string $firstName, string $lastName): ?Member {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq($qb->func()->lower('first_name'), $qb->createNamedParameter(mb_strtolower(trim($firstName)))))
            ->andWhere($qb->expr()->eq($qb->func()->lower('last_name'), $qb->createNamedParameter(mb_strtolower(trim($lastName)))));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }
}
