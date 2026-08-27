<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Controller;

use OCA\BirthdayReminder\AppInfo\Application;
use OCA\BirthdayReminder\Settings\MemberAreaAccess;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

/**
 * Serves the "Mitgliederregister" page in the top navigation bar. Gated by
 * MemberAreaAccess - both "Geburtstagserinnerung Verantwortliche" and
 * "Geburtstagserinnerung Admin" (plus real Nextcloud admins) get in; the
 * separate, stricter AdminSettings delegation guards only the
 * Admin-Einstellungen page/API (AdminApiController).
 */
class PageController extends Controller {
    public function __construct(string $appName, IRequest $request) {
        parent::__construct($appName, $request);
    }

    #[NoCSRFRequired]
    #[AuthorizedAdminSetting(settings: MemberAreaAccess::class)]
    public function index(): TemplateResponse {
        Util::addScript(Application::APP_ID, 'members');
        Util::addStyle(Application::APP_ID, 'settings-admin');
        Util::addStyle(Application::APP_ID, 'members');
        return new TemplateResponse(Application::APP_ID, 'main');
    }
}
