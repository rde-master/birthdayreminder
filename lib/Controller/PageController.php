<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Controller;

use OCA\BirthdayReminder\AppInfo\Application;
use OCA\BirthdayReminder\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

/**
 * Serves the "Mitgliederregister" page in the top navigation bar. Gated by
 * the same delegated-admin permission as the admin settings page - the
 * member registry contains personal data (birthdates, e-mail) that only
 * the club's admin/board group should see or edit.
 */
class PageController extends Controller {
    public function __construct(string $appName, IRequest $request) {
        parent::__construct($appName, $request);
    }

    #[NoCSRFRequired]
    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function index(): TemplateResponse {
        Util::addScript(Application::APP_ID, 'members');
        Util::addStyle(Application::APP_ID, 'settings-admin');
        Util::addStyle(Application::APP_ID, 'members');
        return new TemplateResponse(Application::APP_ID, 'main');
    }
}
