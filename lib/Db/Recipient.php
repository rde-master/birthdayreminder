<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getRecipientType()
 * @method void setRecipientType(string $recipientType)
 * @method string getRecipientValue()
 * @method void setRecipientValue(string $recipientValue)
 * @method bool getOnlyMilestones()
 * @method void setOnlyMilestones(bool $onlyMilestones)
 * @method bool getBirthdateInSubject()
 * @method void setBirthdateInSubject(bool $birthdateInSubject)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class Recipient extends Entity {
    public const TYPE_USER = 'user';
    public const TYPE_GROUP = 'group';
    public const TYPE_EMAIL = 'email';

    protected $recipientType;
    protected $recipientValue;
    protected $onlyMilestones;
    protected $birthdateInSubject;
    protected $createdAt;

    public function __construct() {
        $this->addType('onlyMilestones', 'boolean');
        $this->addType('birthdateInSubject', 'boolean');
        $this->addType('createdAt', 'integer');
    }
}
