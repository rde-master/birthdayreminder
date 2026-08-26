<?php

declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'adminApi#getAddressBooks', 'url' => '/admin/addressbooks', 'verb' => 'GET'],
        ['name' => 'adminApi#saveAddressBook', 'url' => '/admin/addressbook', 'verb' => 'POST'],

        ['name' => 'adminApi#getRecipients', 'url' => '/admin/recipients', 'verb' => 'GET'],
        ['name' => 'adminApi#saveRecipient', 'url' => '/admin/recipients', 'verb' => 'POST'],
        ['name' => 'adminApi#deleteRecipient', 'url' => '/admin/recipients/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],

        ['name' => 'adminApi#getMilestones', 'url' => '/admin/milestones', 'verb' => 'GET'],
        ['name' => 'adminApi#saveMilestone', 'url' => '/admin/milestones', 'verb' => 'POST'],
        ['name' => 'adminApi#deleteMilestone', 'url' => '/admin/milestones/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],

        ['name' => 'adminApi#getCongratsTemplate', 'url' => '/admin/congrats-template', 'verb' => 'GET'],
        ['name' => 'adminApi#saveCongratsTemplate', 'url' => '/admin/congrats-template', 'verb' => 'POST'],

        ['name' => 'personalApi#getSettings', 'url' => '/personal/settings', 'verb' => 'GET'],
        ['name' => 'personalApi#saveSettings', 'url' => '/personal/settings', 'verb' => 'POST'],
    ],
];
