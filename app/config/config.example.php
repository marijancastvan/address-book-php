<?php

declare(strict_types=1);

/**
 * Safe example configuration. Copy to config.local.php for local credentials.
 */
return [
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'address_book',
        'charset' => 'utf8mb4',
        'username' => 'root',
        'password' => '',
    ],
    'app' => [
        'base_url' => 'http://localhost:8000',
    ],
    'mail' => [
        'from_address' => 'castvan.marian@gmail.com',
        'from_name' => 'Address Book',
        'smtp_host' => 'smtp.gmail.com',
        'smtp_port' => 587,
        'smtp_username' => 'castvan.marian@gmail.com',
        'smtp_password' => '',
        'smtp_encryption' => 'tls',
    ],
];
