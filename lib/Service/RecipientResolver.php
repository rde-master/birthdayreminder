<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Service;

use OCA\BirthdayReminder\Db\Recipient;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Resolves recipient rows (user/group/email) into concrete, deduplicated
 * e-mail addresses.
 */
final class RecipientResolver {
    public function __construct(
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return string[] deduplicated e-mail addresses
     */
    public function resolveEmails(Recipient $recipient): array {
        return match ($recipient->getRecipientType()) {
            Recipient::TYPE_EMAIL => [$recipient->getRecipientValue()],
            Recipient::TYPE_USER => $this->resolveUser($recipient->getRecipientValue()),
            Recipient::TYPE_GROUP => $this->resolveGroup($recipient->getRecipientValue()),
            default => [],
        };
    }

    /**
     * @return string[]
     */
    private function resolveUser(string $uid): array {
        $user = $this->userManager->get($uid);
        if ($user === null) {
            $this->logger->warning('birthdayreminder: recipient user not found', ['uid' => $uid]);
            return [];
        }
        $email = $user->getEMailAddress();
        if ($email === null || $email === '') {
            $this->logger->warning('birthdayreminder: recipient user has no e-mail address', ['uid' => $uid]);
            return [];
        }
        return [$email];
    }

    /**
     * @return string[]
     */
    private function resolveGroup(string $gid): array {
        $group = $this->groupManager->get($gid);
        if ($group === null) {
            $this->logger->warning('birthdayreminder: recipient group not found', ['gid' => $gid]);
            return [];
        }
        $emails = [];
        foreach ($group->getUsers() as $user) {
            $email = $user->getEMailAddress();
            if ($email !== null && $email !== '') {
                $emails[] = $email;
            }
        }
        return $emails;
    }

    /**
     * @param Recipient[] $recipients
     * @return string[] deduplicated across all given recipients
     */
    public function resolveAllEmails(array $recipients): array {
        $emails = [];
        foreach ($recipients as $recipient) {
            foreach ($this->resolveEmails($recipient) as $email) {
                $emails[strtolower($email)] = $email;
            }
        }
        return array_values($emails);
    }
}
