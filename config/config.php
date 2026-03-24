<?php
declare(strict_types=1);

return [
    'app' => [
        'name' => 'Foreign Trade Inquiry Manager',
        'env' => getenv('APP_ENV') ?: 'production',
        'debug' => (bool) (getenv('APP_DEBUG') ?: false),
        'timezone' => 'Asia/Shanghai',
        'base_url' => getenv('APP_BASE_URL') ?: 'http://localhost',
    ],
    'security' => [
        'api_header' => 'X-API-KEY',
    ],
    'logging' => [
        'path' => __DIR__ . '/../logs/app.log',
    ],
];
