<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Service;

use OCA\BirthdayReminder\Model\Member;
use OCP\Mail\IMailer;

/**
 * Sends both kinds of mail via Nextcloud's own configured mail transport
 * (OCP\Mail\IMailer) - no separate SMTP credentials needed.
 */
final class MailService {
    public function __construct(
        private IMailer $mailer,
    ) {
    }

    public function sendReminder(string $toEmail, Member $member, int $daysBefore, ?string $giftText): void {
        $subject = $this->reminderSubject($member, $daysBefore);

        $template = $this->mailer->createEMailTemplate('birthdayreminder.reminder', [
            'name' => $member->displayName,
            'daysBefore' => $daysBefore,
        ]);
        $template->addHeader();
        $template->addHeading($subject);
        $template->addBodyText($this->reminderBody($member, $daysBefore));
        if ($giftText !== null) {
            $template->addBodyText('🎉 Runder Geburtstag! Geschenkvorschlag: ' . $giftText);
        }
        $template->addFooter();

        $message = $this->mailer->createMessage();
        $message->setTo([$toEmail]);
        $message->setSubject($subject);
        $message->useTemplate($template);
        $this->mailer->send($message);
    }

    public function sendCongratulation(string $toEmail, string $subject, string $body): void {
        $template = $this->mailer->createEMailTemplate('birthdayreminder.congrats', []);
        $template->addHeader();
        $template->addHeading($subject);
        $template->addBodyText($body);
        $template->addFooter();

        $message = $this->mailer->createMessage();
        $message->setTo([$toEmail]);
        $message->setSubject($subject);
        $message->useTemplate($template);
        $this->mailer->send($message);
    }

    private function reminderSubject(Member $member, int $daysBefore): string {
        if ($daysBefore === 0) {
            return sprintf('%s hat heute Geburtstag', $member->displayName);
        }
        return sprintf('%s hat in %d Tag(en) Geburtstag', $member->displayName, $daysBefore);
    }

    private function reminderBody(Member $member, int $daysBefore): string {
        $when = $daysBefore === 0 ? 'heute' : sprintf('in %d Tag(en)', $daysBefore);
        return sprintf('%s hat %s Geburtstag.', $member->displayName, $when);
    }
}
