<?php

return [
    'driver' => env('MAIL_MAILER', 'log'),
    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', 'smtp.mailgun.org'),
            'port' => env('MAIL_PORT', 587),
            'encryption' => env('MAIL_ENCRYPTION', 'tls'),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ],
        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],
        'array' => ['transport' => 'array'],
        'failover' => [
            'transport' => 'failover',
            'mailers' => explode(',', env('MAIL_MAILERS', 'smtp,log')),
        ],
    ],
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'noreply@rcerp.local'),
        'name' => env('MAIL_FROM_NAME', 'Remote Center ERP'),
    ],
];
