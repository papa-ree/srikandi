<?php

return [
    'type' => 'landlord',

    'groups' => [
        [
            'key' => 'srikandi',
            'label' => 'Srikandi',
            'icon' => 'clipboard',
            'items' => [
                [
                    'label' => 'Status',
                    'url' => 'srikandi/status',
                    'icon' => 'list-checks',
                    'permission' => 'srikandi.status.read',
                ],
            ],
        ],
    ],
];
