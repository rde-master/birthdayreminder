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

    /**
     * @return bool true if the mail was handed off without a failed recipient.
     *              IMailer::send() does not throw on delivery failure (Nextcloud
     *              logs it and returns the list of failed addresses instead), so
     *              callers must check this return value rather than assume success.
     */
    public function sendReminder(string $toEmail, Member $member, int $daysBefore, ?string $giftText): bool {
        $subject = $this->reminderSubject($member, $daysBefore);

        $template = $this->mailer->createEMailTemplate('birthdayreminder.reminder', [
            'name' => $member->displayName,
            'daysBefore' => $daysBefore,
        ]);
        // useTemplate() below pulls the actual mail Subject header from the
        // template's own renderSubject(), not from Message::setSubject() -
        // without this line the mail goes out with an empty subject.
        $template->setSubject($subject);
        // No addHeader() on purpose: it renders a large colored banner with
        // the (theming) logo, which is more than this simple club mail needs.
        $template->addHeading($subject);
        $template->addBodyText($this->reminderBody($member, $daysBefore));
        if ($giftText !== null) {
            $template->addBodyText('🎉 Runder Geburtstag! Geschenkvorschlag: ' . $giftText);
        }
        $template->addFooter();

        $message = $this->mailer->createMessage();
        $message->setTo([$toEmail]);
        $message->useTemplate($template);
        $failedRecipients = $this->mailer->send($message);
        return empty($failedRecipients);
    }

    /**
     * @return bool true if the mail was handed off without a failed recipient.
     */
    public function sendCongratulation(string $toEmail, string $subject, string $body): bool {
        $template = $this->mailer->createEMailTemplate('birthdayreminder.congrats', []);
        $template->setSubject($subject);
        // No addHeader() on purpose: it renders a large colored banner with
        // the (theming) logo, which is more than this simple club mail needs.
        $template->addHeading($subject);
        $template->addBodyText($body);
        $template->addFooter();

        $message = $this->mailer->createMessage();
        $message->setTo([$toEmail]);
        $message->useTemplate($template);
        $failedRecipients = $this->mailer->send($message);
        return empty($failedRecipients);
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
