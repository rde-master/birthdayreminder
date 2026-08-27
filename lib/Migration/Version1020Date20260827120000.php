<?php

declare(strict_types=1);

namespace OCA\BirthdayReminder\Migration;

use Closure;
use OCA\BirthdayReminder\AppInfo\Application;
use OCP\DB\ISchemaWrapper;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the two fixed Nextcloud groups that control access to this app -
 * "Geburtstagserinnerung Verantwortliche" (Mitgliederregister only) and
 * "Geburtstagserinnerung Admin" (Mitgliederregister + Admin-Einstellungen).
 * Idempotent, so it's safe to run again on every future upgrade. Only
 * creates the groups; delegating them to the app's settings classes is
 * still a manual `occ admin-delegation:add` step (see README.md).
 */
class Version1020Date20260827120000 extends SimpleMigrationStep {
    public function __construct(
        private IGroupManager $groupManager,
    ) {
    }

    /**
     * @param Closure(): ISchemaWrapper $schemaClosure
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        foreach ([Application::GROUP_VERANTWORTLICHE, Application::GROUP_ADMIN] as $groupId) {
            if (!$this->groupManager->groupExists($groupId)) {
                $this->groupManager->createGroup($groupId);
                $output->info('birthdayreminder: Gruppe "' . $groupId . '" angelegt');
            }
        }
    }
}
