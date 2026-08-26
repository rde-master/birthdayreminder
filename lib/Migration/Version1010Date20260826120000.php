<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the app's own member registry, replacing the Nextcloud Contacts
 * address book as the data source for birthdays.
 */
class Version1010Date20260826120000 extends SimpleMigrationStep {
    /**
     * @param Closure(): ISchemaWrapper $schemaClosure
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('birthdayreminder_member')) {
            $table = $schema->createTable('birthdayreminder_member');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('first_name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('last_name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('birth_day', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('birth_month', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('birth_year', Types::INTEGER, ['notnull' => false]);
            $table->addColumn('email', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('disabled', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->addColumn('remark', Types::TEXT, ['notnull' => false]);
            $table->addColumn('created_at', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('updated_at', Types::INTEGER, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['last_name', 'first_name'], 'birthdayreminder_member_name');
        }

        return $schema;
    }
}
