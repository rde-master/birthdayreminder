<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Settings;

use OCA\BirthdayReminder\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\IDelegatedSettings;
use OCP\Util;

/**
 * Admin-delegatable settings page: a real Nextcloud admin can grant a
 * specific group access to this page via Settings -> Administration ->
 * Delegation, without making them full instance admins. Delegated here to
 * the "Geburtstagserinnerung Admin" group (see Application::GROUP_ADMIN).
 */
class AdminSettings implements IDelegatedSettings {
    public function getForm(): TemplateResponse {
        Util::addScript(Application::APP_ID, 'settings-admin');
        Util::addStyle(Application::APP_ID, 'settings-admin');
        return new TemplateResponse(Application::APP_ID, 'settings-admin');
    }

    public function getSection(): ?string {
        return 'birthdayreminder';
    }

    public function getPriority(): int {
        return 0;
    }

    public function getName(): ?string {
        return null; // only setting in this section
    }

    public function getAuthorizedAppConfig(): array {
        return [
            Application::APP_ID => [
                '/congrats_subject_template/',
                '/congrats_body_template/',
                '/daily_run_time/',
            ],
        ];
    }
}
