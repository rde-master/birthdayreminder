<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getRecipientId()
 * @method void setRecipientId(int $recipientId)
 * @method int getDaysBefore()
 * @method void setDaysBefore(int $daysBefore)
 */
class Offset extends Entity {
    protected $recipientId;
    protected $daysBefore;

    public function __construct() {
        $this->addType('recipientId', 'integer');
        $this->addType('daysBefore', 'integer');
    }
}
