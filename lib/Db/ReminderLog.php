<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getContactUid()
 * @method void setContactUid(string $contactUid)
 * @method string getReminderType()
 * @method void setReminderType(string $reminderType)
 * @method int getDaysBefore()
 * @method void setDaysBefore(int $daysBefore)
 * @method int getBirthdayYear()
 * @method void setBirthdayYear(int $birthdayYear)
 * @method int getSentAt()
 * @method void setSentAt(int $sentAt)
 */
class ReminderLog extends Entity {
    public const TYPE_OFFSET = 'offset';
    public const TYPE_CONGRATS = 'congrats';

    /**
     * Sentinel for days_before on TYPE_CONGRATS rows (which have no offset).
     * A fixed value rather than NULL, because MySQL/MariaDB treat NULL as
     * distinct from itself in unique indexes - a NULL sentinel would silently
     * defeat the idempotency guarantee for congrats mails.
     */
    public const NO_OFFSET = -1;

    protected $contactUid;
    protected $reminderType;
    protected $daysBefore;
    protected $birthdayYear;
    protected $sentAt;

    public function __construct() {
        $this->addType('daysBefore', 'integer');
        $this->addType('birthdayYear', 'integer');
        $this->addType('sentAt', 'integer');
    }
}
