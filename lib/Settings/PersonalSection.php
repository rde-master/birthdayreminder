<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class PersonalSection implements IIconSection {
    public function __construct(
        private IURLGenerator $url,
        private IL10N $l,
    ) {
    }

    public function getIcon(): string {
        return $this->url->imagePath('core', 'places/calendar.svg');
    }

    public function getID(): string {
        return 'birthdayreminder';
    }

    public function getName(): string {
        return $this->l->t('Geburtstagserinnerung');
    }

    public function getPriority(): int {
        return 75;
    }
}
