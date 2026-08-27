<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\AppInfo;

use OCA\BirthdayReminder\Dashboard\BirthdayWidget;
use OCA\BirthdayReminder\Listener\LoadNavigationEntryListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Navigation\Events\LoadAdditionalEntriesEvent;

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
        // Registers the top-nav entry only for authorized users (see
        // LoadNavigationEntryListener for why this can't happen in boot()).
        $context->registerEventListener(LoadAdditionalEntriesEvent::class, LoadNavigationEntryListener::class);
    }

    public function boot(IBootContext $context): void {
    }
}
