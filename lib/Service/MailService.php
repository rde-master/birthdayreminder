<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Service;

use DateTimeImmutable;
use OCA\BirthdayReminder\Model\Member;
use OCP\Mail\IMailer;
use OCP\Util;

/**
 * Sends both kinds of mail via Nextcloud's own configured mail transport
 * (OCP\Mail\IMailer) - no separate SMTP credentials needed.
 *
 * Deliberately plain-text only, without OCP\Mail\IEMailTemplate: that
 * template builds a branded HTML card (logo banner, footer, nested table
 * layout for email-client compatibility) that leaves visible chrome/blank
 * space behind even with the header/footer calls skipped. A simple text
 * mail sidesteps all of that and is all a club notification needs anyway -
 * it also preserves line breaks from the admin's template text for free.
 */
final class MailService {
    private const SENDER_NAME = 'Geburtstagserinnerung';

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
    public function sendReminder(string $toEmail, Member $member, int $daysBefore, DateTimeImmutable $targetDate, ?int $age, ?string $giftText): bool {
        $subject = $this->reminderSubject($member, $daysBefore, $age);

        $body = $this->reminderBody($member, $daysBefore, $targetDate, $age);
        if ($giftText !== null) {
            $body .= "\n\n🎉 Runder Geburtstag! Geschenkvorschlag: " . $giftText;
        }

        return $this->send($toEmail, $subject, $body);
    }

    /**
     * @return bool true if the mail was handed off without a failed recipient.
     */
    public function sendCongratulation(string $toEmail, string $subject, string $body): bool {
        return $this->send($toEmail, $subject, $body);
    }

    private function send(string $toEmail, string $subject, string $body): bool {
        $message = $this->mailer->createMessage();
        $message->setTo([$toEmail]);
        // Same address Nextcloud's own Mailer defaults to (respects the
        // configured mail_from_address/mail_domain), just with our own
        // display name instead of the instance's theming name ("Nextcloud").
        $message->setFrom([Util::getDefaultEmailAddress('no-reply') => self::SENDER_NAME]);
        $message->setSubject($subject);
        $message->setPlainBody($body);
        $failedRecipients = $this->mailer->send($message);
        return empty($failedRecipients);
    }

    private function reminderSubject(Member $member, int $daysBefore, ?int $age): string {
        $ageStr = $age !== null ? sprintf(' (wird %d)', $age) : ' (Alter unbekannt)';
        if ($daysBefore === 0) {
            return sprintf('%s hat heute Geburtstag%s', $member->displayName, $ageStr);
        }
        return sprintf('%s hat in %d Tag(en) Geburtstag%s', $member->displayName, $daysBefore, $ageStr);
    }

    private function reminderBody(Member $member, int $daysBefore, DateTimeImmutable $targetDate, ?int $age): string {
        $when = $daysBefore === 0 ? 'heute' : sprintf('in %d Tag(en)', $daysBefore);
        $weekday = GermanDate::weekdayName($targetDate);
        $dateStr = $targetDate->format('d.m.Y');
        $ageStr = $age !== null ? sprintf(' und wird %d Jahre alt', $age) : ' (das Alter ist nicht bekannt, da kein Geburtsjahr hinterlegt ist)';

        return sprintf('%s hat %s Geburtstag (%s, %s)%s.', $member->displayName, $when, $weekday, $dateStr, $ageStr);
    }
}
