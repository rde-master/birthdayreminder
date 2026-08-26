<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
    public const APP_ID = 'birthdayreminder';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        // Dashboard widget registration is added here in M5, once
        // Dashboard\BirthdayWidget exists.
    }

    public function boot(IBootContext $context): void {
    }
}
