<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\BackgroundJob;

use OCA\BirthdayReminder\Service\ClockService;
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
        private ClockService $clockService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        // The actual reminder pass only happens once per day, at/after the
        // admin-configured time (see ScheduleGate) - but the framework must
        // invoke run() often enough to notice when that time has passed, so
        // the TimedJob interval itself stays short (hourly).
        $this->setInterval(60 * 60);
        // TIME_SENSITIVE, not TIME_INSENSITIVE: the entire point of this
        // feature is sending at the configured time. TIME_INSENSITIVE
        // sounds harmless ("ok to delay during high load") but has a sharp
        // edge - if the admin has configured Nextcloud's "maintenance_window_start"
        // (Settings -> Administration -> Basic settings -> "background jobs"),
        // CLI cron restricts itself to time-sensitive jobs *outside* that
        // ~4h low-load window, so a time-insensitive job simply never gets
        // picked at all the rest of the day (confirmed live: this is why
        // reminders silently stopped firing on a real deployment that had
        // that setting configured).
        $this->setTimeSensitivity(self::TIME_SENSITIVE);
    }

    protected function run($argument): void {
        $now = $this->clockService->now();
        $configuredTime = $this->configService->getDailyRunTime();

        if (!$this->scheduleGate->shouldRunNow($configuredTime, $now, $this->configService->getLastRunDate())) {
            return;
        }

        $this->logger->info('birthdayreminder: starting daily run', ['configuredTime' => $configuredTime]);
        $this->reminderService->run($this->clockService->today());
        $this->configService->setLastRunDate($now->format('Y-m-d'));
        $this->logger->info('birthdayreminder: daily run finished');
    }
}
