<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A club member in the app's own registry (replaces the earlier Nextcloud
 * Contacts address book as the data source).
 *
 * @method string getFirstName()
 * @method void setFirstName(string $firstName)
 * @method string getLastName()
 * @method void setLastName(string $lastName)
 * @method int getBirthDay()
 * @method void setBirthDay(int $birthDay)
 * @method int getBirthMonth()
 * @method void setBirthMonth(int $birthMonth)
 * @method int|null getBirthYear()
 * @method void setBirthYear(?int $birthYear)
 * @method string|null getEmail()
 * @method void setEmail(?string $email)
 * @method bool getDisabled()
 * @method void setDisabled(bool $disabled)
 * @method string|null getRemark()
 * @method void setRemark(?string $remark)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Member extends Entity {
    protected $firstName;
    protected $lastName;
    protected $birthDay;
    protected $birthMonth;
    protected $birthYear;
    protected $email;
    protected $disabled;
    protected $remark;
    protected $createdAt;
    protected $updatedAt;

    public function __construct() {
        $this->addType('birthDay', 'integer');
        $this->addType('birthMonth', 'integer');
        $this->addType('birthYear', 'integer');
        $this->addType('disabled', 'boolean');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
    }

    public function getDisplayName(): string {
        return trim($this->getFirstName() . ' ' . $this->getLastName());
    }
}
