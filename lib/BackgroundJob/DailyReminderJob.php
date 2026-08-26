<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\BackgroundJob;

use DateTimeImmutable;
use OCA\BirthdayReminder\Service\ConfigService;
use OCA\BirthdayReminder\Service\ReminderService;
use OCA\BirthdayReminder\Service\ScheduleGate;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

class DailyReminderJob extends TimedJob {
    public function __construct(
        ITimeFactory $time,
        private ReminderService $reminderService,
        private ConfigService $configService,
        private ScheduleGate $scheduleGate,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        // The actual reminder pass only happens once per day, at/after the
        // admin-configured time (see ScheduleGate) - but the framework must
        // invoke run() often enough to notice when that time has passed, so
        // the TimedJob interval itself stays short (hourly).
        $this->setInterval(60 * 60);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        $now = new DateTimeImmutable('now');
        $configuredTime = $this->configService->getDailyRunTime();

        if (!$this->scheduleGate->shouldRunNow($configuredTime, $now, $this->configService->getLastRunDate())) {
            return;
        }

        $this->logger->info('birthdayreminder: starting daily run', ['configuredTime' => $configuredTime]);
        $this->reminderService->run($now);
        $this->configService->setLastRunDate($now->format('Y-m-d'));
        $this->logger->info('birthdayreminder: daily run finished');
    }
}
