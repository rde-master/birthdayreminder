<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Service;

use OCA\BirthdayReminder\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Thin wrapper around IAppConfig for this app's few scalar settings.
 */
final class ConfigService {
    private const KEY_CONGRATS_SUBJECT = 'congrats_subject_template';
    private const KEY_CONGRATS_BODY = 'congrats_body_template';
    private const KEY_DAILY_RUN_TIME = 'daily_run_time';
    private const KEY_LAST_RUN_DATE = 'last_run_date';

    public const DEFAULT_CONGRATS_SUBJECT = 'Herzlichen Glückwunsch zum Geburtstag, {vorname}!';
    public const DEFAULT_CONGRATS_BODY = "Liebe/r {vorname},\n\nwir wünschen dir alles Gute zu deinem {alter}. Geburtstag am {datum}!\n\nHerzliche Grüße";
    public const DEFAULT_DAILY_RUN_TIME = '08:00';

    public function __construct(
        private IAppConfig $appConfig,
    ) {
    }

    /** Format "HH:MM", 24h. */
    public function getDailyRunTime(): string {
        return $this->appConfig->getValueString(Application::APP_ID, self::KEY_DAILY_RUN_TIME, self::DEFAULT_DAILY_RUN_TIME);
    }

    public function setDailyRunTime(string $time): void {
        $this->appConfig->setValueString(Application::APP_ID, self::KEY_DAILY_RUN_TIME, $time);
    }

    /** Format "Y-m-d", or null if the scheduled job has never completed a run yet. */
    public function getLastRunDate(): ?string {
        $value = $this->appConfig->getValueString(Application::APP_ID, self::KEY_LAST_RUN_DATE, '');
        return $value !== '' ? $value : null;
    }

    public function setLastRunDate(string $date): void {
        $this->appConfig->setValueString(Application::APP_ID, self::KEY_LAST_RUN_DATE, $date);
    }

    public function getCongratsSubjectTemplate(): string {
        return $this->appConfig->getValueString(Application::APP_ID, self::KEY_CONGRATS_SUBJECT, self::DEFAULT_CONGRATS_SUBJECT);
    }

    public function setCongratsSubjectTemplate(string $template): void {
        $this->appConfig->setValueString(Application::APP_ID, self::KEY_CONGRATS_SUBJECT, $template);
    }

    public function getCongratsBodyTemplate(): string {
        return $this->appConfig->getValueString(Application::APP_ID, self::KEY_CONGRATS_BODY, self::DEFAULT_CONGRATS_BODY);
    }

    public function setCongratsBodyTemplate(string $template): void {
        $this->appConfig->setValueString(Application::APP_ID, self::KEY_CONGRATS_BODY, $template);
    }
}
