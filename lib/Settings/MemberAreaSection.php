<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Settings;

use OCA\BirthdayReminder\AppInfo\Application;
use OCP\IL10N;
use OCP\Settings\IIconSection;

/**
 * Section required to register MemberAreaAccess for delegation - never
 * actually shown, since MemberAreaAccess has no visible settings page.
 */
class MemberAreaSection implements IIconSection {
    public function __construct(
        private IL10N $l,
    ) {
    }

    public function getID(): string {
        return Application::APP_ID . '-members';
    }

    public function getName(): string {
        return $this->l->t('Geburtstagserinnerung: Mitgliederregister');
    }

    public function getPriority(): int {
        return 76;
    }

    public function getIcon(): string {
        return '';
    }
}
