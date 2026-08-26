<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Controller;

use OCA\BirthdayReminder\Db\Offset;
use OCA\BirthdayReminder\Db\OffsetMapper;
use OCA\BirthdayReminder\Db\Recipient;
use OCA\BirthdayReminder\Db\RecipientMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class PersonalApiController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $userSession,
        private RecipientMapper $recipientMapper,
        private OffsetMapper $offsetMapper,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function getSettings(): JSONResponse {
        $uid = $this->currentUid();
        $recipient = $this->recipientMapper->findByTypeAndValue(Recipient::TYPE_USER, $uid);

        if ($recipient === null) {
            return new JSONResponse(['offsets' => [], 'onlyMilestones' => false]);
        }

        return new JSONResponse([
            'offsets' => array_map(fn (Offset $o) => $o->getDaysBefore(), $this->offsetMapper->findByRecipientId($recipient->getId())),
            'onlyMilestones' => $recipient->getOnlyMilestones(),
        ]);
    }

    /**
     * @param int[] $offsets
     */
    #[NoAdminRequired]
    public function saveSettings(bool $onlyMilestones, array $offsets): JSONResponse {
        $uid = $this->currentUid();
        $recipient = $this->recipientMapper->findOrCreate(Recipient::TYPE_USER, $uid);
        $recipient->setOnlyMilestones($onlyMilestones);
        $this->recipientMapper->update($recipient);

        $this->offsetMapper->deleteByRecipientId($recipient->getId());
        foreach (array_unique(array_map('intval', $offsets)) as $daysBefore) {
            $this->offsetMapper->add($recipient->getId(), $daysBefore);
        }

        return new JSONResponse(['ok' => true]);
    }

    private function currentUid(): string {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new \RuntimeException('no logged-in user');
        }
        return $user->getUID();
    }
}
