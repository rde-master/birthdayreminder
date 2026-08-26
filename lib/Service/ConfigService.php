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

    public const DEFAULT_CONGRATS_SUBJECT = 'Herzlichen Glückwunsch zum Geburtstag, {vorname}!';
    public const DEFAULT_CONGRATS_BODY = "Liebe/r {vorname},\n\nder gesamte Verein wünscht dir alles Gute zu deinem {alter}. Geburtstag am {datum}!\n\nHerzliche Grüße\nDein Verein";

    public function __construct(
        private IAppConfig $appConfig,
    ) {
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
