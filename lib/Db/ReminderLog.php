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
 * @method string getRecipientEmail()
 * @method void setRecipientEmail(string $recipientEmail)
 * @method int getSentAt()
 * @method void setSentAt(int $sentAt)
 */
class ReminderLog extends Entity {
    public const TYPE_OFFSET = 'offset';
    public const TYPE_CONGRATS = 'congrats';

    /**
     * Marker row written once per completed run() that found nothing to
     * send at all - lets the admin tell "checked, nothing due today" apart
     * from "the job never ran". Has no member/recipient of its own; see
     * ReminderLogMapper::logNoMailSent().
     */
    public const TYPE_NONE = 'none';

    /**
     * Sentinel for days_before on TYPE_CONGRATS/TYPE_NONE rows (which have
     * no offset). A fixed value rather than NULL, because MySQL/MariaDB
     * treat NULL as distinct from itself in unique indexes - a NULL
     * sentinel would silently defeat the idempotency guarantee.
     */
    public const NO_OFFSET = -1;

    /**
     * Sentinel for recipient_email on TYPE_NONE rows (no recipient exists).
     * Same NULL-in-unique-index reasoning as NO_OFFSET above.
     */
    public const NO_RECIPIENT = '';

    protected $contactUid;
    protected $reminderType;
    protected $daysBefore;
    protected $birthdayYear;
    protected $recipientEmail;
    protected $sentAt;

    public function __construct() {
        $this->addType('daysBefore', 'integer');
        $this->addType('birthdayYear', 'integer');
        $this->addType('sentAt', 'integer');
    }
}
