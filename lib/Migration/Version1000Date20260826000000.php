<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20260826000000 extends SimpleMigrationStep {
    /**
     * @param Closure(): ISchemaWrapper $schemaClosure
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('birthdayreminder_recipient')) {
            $table = $schema->createTable('birthdayreminder_recipient');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('recipient_type', Types::STRING, ['notnull' => true, 'length' => 16]);
            $table->addColumn('recipient_value', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('only_milestones', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->addColumn('created_at', Types::INTEGER, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['recipient_type', 'recipient_value'], 'birthdayreminder_recip_uniq');
        }

        if (!$schema->hasTable('birthdayreminder_offset')) {
            $table = $schema->createTable('birthdayreminder_offset');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('recipient_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('days_before', Types::INTEGER, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['recipient_id', 'days_before'], 'birthdayreminder_offset_uniq');
        }

        if (!$schema->hasTable('birthdayreminder_milestone')) {
            $table = $schema->createTable('birthdayreminder_milestone');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('age', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('gift_text', Types::TEXT, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['age'], 'birthdayreminder_milestone_uniq');
        }

        if (!$schema->hasTable('birthdayreminder_log')) {
            $table = $schema->createTable('birthdayreminder_log');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('contact_uid', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('reminder_type', Types::STRING, ['notnull' => true, 'length' => 16]);
            $table->addColumn('days_before', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('birthday_year', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('sent_at', Types::INTEGER, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(
                ['contact_uid', 'reminder_type', 'days_before', 'birthday_year'],
                'birthdayreminder_log_uniq'
            );
        }

        return $schema;
    }
}
