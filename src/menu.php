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
