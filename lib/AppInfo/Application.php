<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\AppInfo;

use Closure;
use OCA\BirthdayReminder\Dashboard\BirthdayWidget;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IGroupManager;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUserSession;

class Application extends App implements IBootstrap {
    public const APP_ID = 'birthdayreminder';

    /**
     * Members registry + Übersicht/CSV-Import/Logs (not the Admin-Einstellungen).
     */
    public const GROUP_VERANTWORTLICHE = 'Geburtstagserinnerung Verantwortliche';

    /**
     * Members registry AND the Admin-Einstellungen (recipients, milestones,
     * mail template, schedule).
     */
    public const GROUP_ADMIN = 'Geburtstagserinnerung Admin';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerDashboardWidget(BirthdayWidget::class);
    }

    /**
     * The top navigation entry is registered dynamically (not declared in
     * info.xml) so that users outside both app groups - and outside the
     * Nextcloud system admins - never see it at all, rather than seeing a
     * link that then 403s.
     */
    public function boot(IBootContext $context): void {
        $context->injectFn(Closure::fromCallable([$this, 'registerNavigationIfAuthorized']));
    }

    private function registerNavigationIfAuthorized(
        IUserSession $userSession,
        IGroupManager $groupManager,
        INavigationManager $navigationManager,
        IURLGenerator $urlGenerator,
    ): void {
        $user = $userSession->getUser();
        if ($user === null) {
            return;
        }

        $uid = $user->getUID();
        $authorized = $groupManager->isAdmin($uid)
            || $groupManager->isInGroup($uid, self::GROUP_VERANTWORTLICHE)
            || $groupManager->isInGroup($uid, self::GROUP_ADMIN);
        if (!$authorized) {
            return;
        }

        $navigationManager->add(static function () use ($urlGenerator): array {
            return [
                'id' => Application::APP_ID,
                'order' => 50,
                'href' => $urlGenerator->linkToRoute(Application::APP_ID . '.page.index'),
                'icon' => $urlGenerator->imagePath(Application::APP_ID, 'app.svg'),
                'name' => 'Geburtstagserinnerung',
                'type' => INavigationManager::TYPE_APPS,
            ];
        });
    }
}
