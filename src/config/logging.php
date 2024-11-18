<?php

return [
    'channels' => [
        'shopify-sync' => [
            'driver' => 'daily',
            'path' => storage_path('logs/my-package.log'),
            'level' => 'debug',
            'days' => 7,
        ],
    ],
];
