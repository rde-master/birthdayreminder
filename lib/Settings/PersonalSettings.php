<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Settings;

use OCA\BirthdayReminder\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

class PersonalSettings implements ISettings {
    public function getForm(): TemplateResponse {
        Util::addScript(Application::APP_ID, 'settings-personal');
        Util::addStyle(Application::APP_ID, 'settings-admin');
        return new TemplateResponse(Application::APP_ID, 'settings-personal');
    }

    public function getSection(): ?string {
        return 'birthdayreminder';
    }

    public function getPriority(): int {
        return 0;
    }
}
