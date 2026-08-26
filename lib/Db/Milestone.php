<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getAge()
 * @method void setAge(int $age)
 * @method string getGiftText()
 * @method void setGiftText(string $giftText)
 */
class Milestone extends Entity {
    protected $age;
    protected $giftText;

    public function __construct() {
        $this->addType('age', 'integer');
    }
}
