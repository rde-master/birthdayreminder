<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Controller;

use OCA\BirthdayReminder\Db\Milestone;
use OCA\BirthdayReminder\Db\MilestoneMapper;
use OCA\BirthdayReminder\Db\OffsetMapper;
use OCA\BirthdayReminder\Db\Recipient;
use OCA\BirthdayReminder\Db\RecipientMapper;
use OCA\BirthdayReminder\Db\ReminderLogMapper;
use OCA\BirthdayReminder\Service\ConfigService;
use OCA\BirthdayReminder\Service\ReminderService;
use OCA\BirthdayReminder\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class AdminApiController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private RecipientMapper $recipientMapper,
        private OffsetMapper $offsetMapper,
        private MilestoneMapper $milestoneMapper,
        private ConfigService $configService,
        private ReminderService $reminderService,
        private ReminderLogMapper $reminderLogMapper,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function getRecipients(): JSONResponse {
        return new JSONResponse(array_map(
            fn (Recipient $r) => $this->serializeRecipient($r),
            $this->recipientMapper->findAll()
        ));
    }

    /**
     * @param int[] $offsets
     */
    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function saveRecipient(?int $id, string $type, string $value, bool $onlyMilestones, array $offsets): JSONResponse {
        if (!in_array($type, [Recipient::TYPE_USER, Recipient::TYPE_GROUP, Recipient::TYPE_EMAIL], true)) {
            return new JSONResponse(['error' => 'invalid type'], 400);
        }

        if ($id !== null) {
            try {
                $recipient = $this->recipientMapper->find($id);
            } catch (DoesNotExistException) {
                return new JSONResponse(['error' => 'not found'], 404);
            }
            $recipient->setRecipientType($type);
            $recipient->setRecipientValue($value);
            $recipient->setOnlyMilestones($onlyMilestones);
            $this->recipientMapper->update($recipient);
        } else {
            $recipient = $this->recipientMapper->findOrCreate($type, $value);
            $recipient->setOnlyMilestones($onlyMilestones);
            $this->recipientMapper->update($recipient);
        }

        $this->offsetMapper->deleteByRecipientId($recipient->getId());
        foreach (array_unique(array_map('intval', $offsets)) as $daysBefore) {
            $this->offsetMapper->add($recipient->getId(), $daysBefore);
        }

        return new JSONResponse($this->serializeRecipient($recipient));
    }

    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function deleteRecipient(int $id): JSONResponse {
        try {
            $recipient = $this->recipientMapper->find($id);
        } catch (DoesNotExistException) {
            return new JSONResponse(['error' => 'not found'], 404);
        }
        $this->offsetMapper->deleteByRecipientId($id);
        $this->recipientMapper->delete($recipient);
        return new JSONResponse(['ok' => true]);
    }

    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function getMilestones(): JSONResponse {
        return new JSONResponse(array_map(
            fn (Milestone $m) => ['id' => $m->getId(), 'age' => $m->getAge(), 'giftText' => $m->getGiftText()],
            $this->milestoneMapper->findAll()
        ));
    }

    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function saveMilestone(?int $id, int $age, string $giftText): JSONResponse {
        if ($id !== null) {
            try {
                $milestone = $this->milestoneMapper->find($id);
            } catch (DoesNotExistException) {
                return new JSONResponse(['error' => 'not found'], 404);
            }
        } else {
            $existing = $this->milestoneMapper->findByAge($age);
            if ($existing !== null) {
                $milestone = $existing;
            } else {
                $milestone = new Milestone();
                $milestone->setAge($age);
            }
        }

        $milestone->setAge($age);
        $milestone->setGiftText($giftText);
        $saved = $milestone->getId() === null ? $this->milestoneMapper->insert($milestone) : $this->milestoneMapper->update($milestone);

        return new JSONResponse(['id' => $saved->getId(), 'age' => $saved->getAge(), 'giftText' => $saved->getGiftText()]);
    }

    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function deleteMilestone(int $id): JSONResponse {
        try {
            $milestone = $this->milestoneMapper->find($id);
        } catch (DoesNotExistException) {
            return new JSONResponse(['error' => 'not found'], 404);
        }
        $this->milestoneMapper->delete($milestone);
        return new JSONResponse(['ok' => true]);
    }

    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function getCongratsTemplate(): JSONResponse {
        return new JSONResponse([
            'subject' => $this->configService->getCongratsSubjectTemplate(),
            'body' => $this->configService->getCongratsBodyTemplate(),
            'defaultSubject' => ConfigService::DEFAULT_CONGRATS_SUBJECT,
            'defaultBody' => ConfigService::DEFAULT_CONGRATS_BODY,
        ]);
    }

    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function saveCongratsTemplate(string $subject, string $body): JSONResponse {
        $this->configService->setCongratsSubjectTemplate($subject);
        $this->configService->setCongratsBodyTemplate($body);
        return new JSONResponse(['ok' => true]);
    }

    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function getSchedule(): JSONResponse {
        return new JSONResponse([
            'dailyRunTime' => $this->configService->getDailyRunTime(),
            'lastRunDate' => $this->configService->getLastRunDate(),
            'remindersEnabled' => $this->configService->getRemindersEnabled(),
            'congratsEnabled' => $this->configService->getCongratsEnabled(),
        ]);
    }

    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function saveSchedule(string $dailyRunTime, bool $remindersEnabled, bool $congratsEnabled): JSONResponse {
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $dailyRunTime) !== 1) {
            return new JSONResponse(['error' => 'Ungültige Uhrzeit (Format HH:MM erwartet)'], 400);
        }
        $this->configService->setDailyRunTime($dailyRunTime);
        $this->configService->setRemindersEnabled($remindersEnabled);
        $this->configService->setCongratsEnabled($congratsEnabled);
        return new JSONResponse(['ok' => true]);
    }

    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function triggerReminders(): JSONResponse {
        $this->logger->info('birthdayreminder: manual trigger - reminders');
        $ran = $this->reminderService->runReminders();
        return new JSONResponse(['ok' => true, 'skippedDisabled' => !$ran]);
    }

    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function triggerCongrats(): JSONResponse {
        $this->logger->info('birthdayreminder: manual trigger - congrats');
        $ran = $this->reminderService->runCongrats();
        return new JSONResponse(['ok' => true, 'skippedDisabled' => !$ran]);
    }

    #[AuthorizedAdminSetting(settings: AdminSettings::class)]
    public function clearLog(): JSONResponse {
        $deleted = $this->reminderLogMapper->deleteAll();
        $this->logger->warning('birthdayreminder: send log cleared manually', ['deletedRows' => $deleted]);
        return new JSONResponse(['ok' => true, 'deleted' => $deleted]);
    }

    private function serializeRecipient(Recipient $r): array {
        return [
            'id' => $r->getId(),
            'type' => $r->getRecipientType(),
            'value' => $r->getRecipientValue(),
            'onlyMilestones' => $r->getOnlyMilestones(),
            'offsets' => array_map(
                fn ($o) => $o->getDaysBefore(),
                $this->offsetMapper->findByRecipientId($r->getId())
            ),
        ];
    }
}
