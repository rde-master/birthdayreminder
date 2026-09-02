<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds recipient_email to the send log: reminders used to log one row per
 * matched member regardless of how many recipient addresses actually
 * received mail for that match, so multiple sends collapsed into a single
 * log entry. Widening the unique index to include recipient_email lets
 * ReminderService log (and gate idempotency on) one row per actually sent
 * e-mail instead.
 */
class Version1040Date20260831000000 extends SimpleMigrationStep {
    /**
     * @param Closure(): ISchemaWrapper $schemaClosure
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('birthdayreminder_log');
        if (!$table->hasColumn('recipient_email')) {
            $table->addColumn('recipient_email', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
        }
        if ($table->hasIndex('birthdayreminder_log_uniq')) {
            $table->dropIndex('birthdayreminder_log_uniq');
        }
        if (!$table->hasIndex('birthdayreminder_log_uniq2')) {
            $table->addUniqueIndex(
                ['contact_uid', 'reminder_type', 'days_before', 'birthday_year', 'recipient_email'],
                'birthdayreminder_log_uniq2'
            );
        }

        return $schema;
    }
}
