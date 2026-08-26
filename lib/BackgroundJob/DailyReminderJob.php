<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\BackgroundJob;

use OCA\BirthdayReminder\Service\ReminderService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

class DailyReminderJob extends TimedJob {
    public function __construct(
        ITimeFactory $time,
        private ReminderService $reminderService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        // Once per day is enough; Nextcloud's cron ticks far more often than that.
        $this->setInterval(24 * 60 * 60);
    }

    protected function run($argument): void {
        $this->logger->info('birthdayreminder: starting daily run');
        $this->reminderService->run();
        $this->logger->info('birthdayreminder: daily run finished');
    }
}
