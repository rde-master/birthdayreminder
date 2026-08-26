<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Service;

/**
 * Simple {placeholder} substitution for the admin-editable congratulation
 * mail template. Pure, no I/O - easy to unit test.
 */
final class MailTemplateRenderer {
    /**
     * @param array<string, string> $placeholders keys without braces, e.g. ['name' => 'Anna Muster']
     */
    public function render(string $template, array $placeholders): string {
        $search = [];
        $replace = [];
        foreach ($placeholders as $key => $value) {
            $search[] = '{' . $key . '}';
            $replace[] = $value;
        }
        return str_replace($search, $replace, $template);
    }
}
