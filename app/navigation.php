<?php
declare(strict_types=1);

return [
    [
        'heading' => null,
        'items' => [
            ['path' => '/', 'label' => 'Dashboard', 'icon' => 'speedometer2', 'roles' => ['admin', 'user']],
            ['path' => '/content', 'label' => 'Content generator', 'icon' => 'pencil-square', 'roles' => ['admin', 'user']],
            ['path' => '/speech', 'label' => 'Text to speech', 'icon' => 'soundwave', 'roles' => ['admin', 'user']],
            ['path' => '/automation', 'label' => 'Automation', 'icon' => 'alarm', 'roles' => ['admin', 'user']],
        ],
    ],
    [
        'heading' => 'Administration',
        'items' => [
            ['path' => '/admin/azure', 'label' => 'Azure deployments', 'icon' => 'cloud', 'roles' => ['admin']],
            ['path' => '/admin/users', 'label' => 'Users', 'icon' => 'people', 'roles' => ['admin']],
        ],
    ],
];
