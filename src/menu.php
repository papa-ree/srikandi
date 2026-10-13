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
                    'label' => 'Overview',
                    'url' => 'srikandi/overview',
                    'icon' => 'bar-chart-3',
                    'permission' => 'srikandi.client.read',
                ],
                [
                    'label' => 'Client',
                    'url' => 'srikandi/client',
                    'icon' => 'users',
                    'permission' => 'srikandi.client.read',
                ],
                [
                    'label' => 'Naskah',
                    'url' => 'srikandi/naskah',
                    'icon' => 'file-text',
                    'permission' => 'srikandi.naskah.read',
                ],
            ],
        ],
    ],
];
