<?php

declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

        // Public, token-protected alternative to Nextcloud's own cron queue - see CronTriggerController.
        ['name' => 'cronTrigger#trigger', 'url' => '/cron-trigger/{token}', 'verb' => 'GET', 'requirements' => ['token' => '[A-Za-z0-9]+']],

        ['name' => 'membersApi#getMembers', 'url' => '/admin/members', 'verb' => 'GET'],
        ['name' => 'membersApi#saveMember', 'url' => '/admin/members', 'verb' => 'POST'],
        ['name' => 'membersApi#deleteMember', 'url' => '/admin/members/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
        ['name' => 'membersApi#importTemplateCsv', 'url' => '/admin/members/import-template', 'verb' => 'GET'],
        ['name' => 'membersApi#importMembers', 'url' => '/admin/members/import', 'verb' => 'POST'],
        ['name' => 'membersApi#importContacts', 'url' => '/admin/members/import-contacts', 'verb' => 'POST'],
        ['name' => 'membersApi#exportContacts', 'url' => '/admin/members/export-contacts', 'verb' => 'POST'],
        ['name' => 'membersApi#exportMembersCsv', 'url' => '/admin/members/export-csv', 'verb' => 'GET'],
        ['name' => 'membersApi#getOverview', 'url' => '/admin/overview', 'verb' => 'GET'],
        ['name' => 'membersApi#getSendLog', 'url' => '/admin/send-log', 'verb' => 'GET'],
        ['name' => 'membersApi#exportSendLogCsv', 'url' => '/admin/send-log/export-csv', 'verb' => 'GET'],
        ['name' => 'membersApi#getGifts', 'url' => '/admin/gifts', 'verb' => 'GET'],

        ['name' => 'adminApi#getRecipients', 'url' => '/admin/recipients', 'verb' => 'GET'],
        ['name' => 'adminApi#saveRecipient', 'url' => '/admin/recipients', 'verb' => 'POST'],
        ['name' => 'adminApi#deleteRecipient', 'url' => '/admin/recipients/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],

        ['name' => 'adminApi#getMilestones', 'url' => '/admin/milestones', 'verb' => 'GET'],
        ['name' => 'adminApi#saveMilestone', 'url' => '/admin/milestones', 'verb' => 'POST'],
        ['name' => 'adminApi#deleteMilestone', 'url' => '/admin/milestones/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],

        ['name' => 'adminApi#getCongratsTemplate', 'url' => '/admin/congrats-template', 'verb' => 'GET'],
        ['name' => 'adminApi#saveCongratsTemplate', 'url' => '/admin/congrats-template', 'verb' => 'POST'],

        ['name' => 'adminApi#getSchedule', 'url' => '/admin/schedule', 'verb' => 'GET'],
        ['name' => 'adminApi#saveSchedule', 'url' => '/admin/schedule', 'verb' => 'POST'],
        ['name' => 'adminApi#getCronTriggerUrl', 'url' => '/admin/cron-trigger-url', 'verb' => 'GET'],
        ['name' => 'adminApi#regenerateCronTriggerToken', 'url' => '/admin/cron-trigger-url/regenerate', 'verb' => 'POST'],
        ['name' => 'adminApi#triggerReminders', 'url' => '/admin/trigger-reminders', 'verb' => 'POST'],
        ['name' => 'adminApi#triggerCongrats', 'url' => '/admin/trigger-congrats', 'verb' => 'POST'],
        ['name' => 'adminApi#clearLog', 'url' => '/admin/log', 'verb' => 'DELETE'],

        ['name' => 'personalApi#getSettings', 'url' => '/personal/settings', 'verb' => 'GET'],
        ['name' => 'personalApi#saveSettings', 'url' => '/personal/settings', 'verb' => 'POST'],
    ],
];
