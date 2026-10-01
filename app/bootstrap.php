<?php

declare(strict_types=1);

require_once __DIR__ . '/session.php';

$localConfigPath = __DIR__ . '/config/config.local.php';
$exampleConfigPath = __DIR__ . '/config/config.example.php';
$configPath = is_file($localConfigPath) ? $localConfigPath : $exampleConfigPath;

$config = require $configPath;

/**
 * Return the shared PDO connection, creating it on first use.
 *
 * @throws PDOException When MySQL is unavailable or the credentials are invalid.
 */
function db(): PDO
{
    static $connection = null;
    global $config;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $database = $config['database'];
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $database['host'],
        $database['port'],
        $database['database'],
        $database['charset'],
    );

    $connection = new PDO($dsn, $database['username'], $database['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $connection;
}
