<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Controller;

use OCA\BirthdayReminder\Service\ClockService;
use OCA\BirthdayReminder\Service\ConfigService;
use OCA\BirthdayReminder\Service\ReminderService;
use OCA\BirthdayReminder\Service\ScheduleGate;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Public, token-protected alternative entry point for the daily reminder
 * check, alongside (not instead of) Nextcloud's own background-job queue.
 *
 * Why this exists: on hosts whose cron feature can only call a URL (e.g.
 * all-inkl's KAS panel has no "run a CLI script" option, only "call this
 * URL"), Nextcloud's normal cron.php processes just one due job per hit,
 * selected from ALL registered jobs across every installed app - with
 * dozens of jobs competing for that one slot, DailyReminderJob can end up
 * stuck behind the queue for hours past the configured time. This endpoint
 * bypasses that shared queue entirely: point an external cronjob straight
 * at this URL instead (or in addition), and every hit runs our own check
 * directly, with zero competition from other apps' jobs.
 *
 * Deliberately time-independent (see ScheduleGate::alreadyRanToday(), which
 * skips the "Tägliche Prüfzeit" comparison from shouldRunNow()) - the
 * caller's own external cron schedule takes over that role. Still only
 * actually sends once per day, and reuses the exact same ReminderService/
 * idempotency-log path as the normal job, so it's safe to run alongside it
 * or to hit repeatedly without duplicate mail.
 */
class CronTriggerController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private ConfigService $configService,
        private ScheduleGate $scheduleGate,
        private ReminderService $reminderService,
        private ClockService $clockService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function trigger(string $token): JSONResponse {
        if (!hash_equals($this->configService->getCronTriggerToken(), $token)) {
            return new JSONResponse(['error' => 'invalid token'], 403);
        }

        $now = $this->clockService->now();
        if ($this->scheduleGate->alreadyRanToday($this->configService->getLastRunDate(), $now)) {
            return new JSONResponse(['ok' => true, 'skippedAlreadyRanToday' => true]);
        }

        $this->logger->info('birthdayreminder: starting daily run (external cron-trigger URL)');
        $this->reminderService->run($this->clockService->today());
        $this->configService->setLastRunDate($now->format('Y-m-d'));
        $this->logger->info('birthdayreminder: daily run finished (external cron-trigger URL)');

        return new JSONResponse(['ok' => true, 'skippedAlreadyRanToday' => false]);
    }
}
