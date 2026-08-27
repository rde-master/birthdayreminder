<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the per-recipient "Geburtsdatum im Betreff" option (default off, so
 * existing recipients keep their current subject line unchanged).
 */
class Version1030Date20260827190000 extends SimpleMigrationStep {
    /**
     * @param Closure(): ISchemaWrapper $schemaClosure
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('birthdayreminder_recipient');
        if (!$table->hasColumn('birthdate_in_subject')) {
            $table->addColumn('birthdate_in_subject', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
        }

        return $schema;
    }
}
