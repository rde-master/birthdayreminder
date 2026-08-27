<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Settings;

use OCA\BirthdayReminder\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\IDelegatedSettings;

/**
 * Permission-only delegated setting with no visible settings page of its
 * own (same pattern as apps/webhook_listeners' Admin settings class). It
 * exists purely so "occ admin-delegation:add ... <group>" can grant a group
 * access to the Mitgliederregister page/API independently of the separate
 * Admin-Einstellungen (AdminSettings) - e.g. "Geburtstagserinnerung
 * Verantwortliche" gets the member registry but not the admin settings,
 * while "Geburtstagserinnerung Admin" gets both.
 */
class MemberAreaAccess implements IDelegatedSettings {
    public function getForm(): TemplateResponse {
        throw new \Exception('MemberAreaAccess has no settings page and should never be rendered');
    }

    public function getSection(): ?string {
        return Application::APP_ID . '-members';
    }

    public function getPriority(): int {
        return 0;
    }

    public function getName(): ?string {
        return null;
    }

    public function getAuthorizedAppConfig(): array {
        return [];
    }
}
