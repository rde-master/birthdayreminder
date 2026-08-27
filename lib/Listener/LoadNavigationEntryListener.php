<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Listener;

use OCA\BirthdayReminder\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Navigation\Events\LoadAdditionalEntriesEvent;

/**
 * Registers the top-nav entry only for users who are a Nextcloud admin or a
 * member of one of this app's two groups - everyone else never sees it at
 * all. Listening for LoadAdditionalEntriesEvent (rather than doing this in
 * Application::boot()) matters: apps are booted before the request's user
 * is authenticated, so IUserSession::getUser() is unreliable that early.
 * This event fires while navigation is actually being assembled, once auth
 * has happened (same pattern as app_api's LoadMenuEntriesListener).
 *
 * @template-implements IEventListener<LoadAdditionalEntriesEvent>
 */
class LoadNavigationEntryListener implements IEventListener {
    public function __construct(
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private INavigationManager $navigationManager,
        private IURLGenerator $urlGenerator,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof LoadAdditionalEntriesEvent) {
            return;
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return;
        }

        $uid = $user->getUID();
        $authorized = $this->groupManager->isAdmin($uid)
            || $this->groupManager->isInGroup($uid, Application::GROUP_VERANTWORTLICHE)
            || $this->groupManager->isInGroup($uid, Application::GROUP_ADMIN);
        if (!$authorized) {
            return;
        }

        $urlGenerator = $this->urlGenerator;
        $this->navigationManager->add(static function () use ($urlGenerator): array {
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
