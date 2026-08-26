<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Milestone>
 */
class MilestoneMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'birthdayreminder_milestone', Milestone::class);
    }

    /**
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     */
    public function find(int $id): Milestone {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \PDO::PARAM_INT)));
        return $this->findEntity($qb);
    }

    /**
     * @return Milestone[]
     */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())->orderBy('age', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * @return int[]
     */
    public function findAllAges(): array {
        return array_map(static fn (Milestone $m) => $m->getAge(), $this->findAll());
    }

    public function findByAge(int $age): ?Milestone {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())
            ->where($qb->expr()->eq('age', $qb->createNamedParameter($age, \PDO::PARAM_INT)));
        try {
            return $this->findEntity($qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return null;
        }
    }
}
