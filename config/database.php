<?php
declare(strict_types=1);

$phpTz = getenv('APP_TIMEZONE') ?: 'Asia/Shanghai';
if ($phpTz !== '') {
    date_default_timezone_set($phpTz);
}

function env_required(string $key): string
{
    $value = getenv($key);
    if ($value === false || trim($value) === '') {
        throw new RuntimeException("Missing required environment variable: {$key}");
    }

    return trim($value);
}

/**
 * 统一 PDO 数据库连接文件
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = env_required('DB_HOST');
    $port = env_required('DB_PORT');
    $name = env_required('DB_NAME');
    $user = env_required('DB_USER');
    $pass = env_required('DB_PASS');

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);

    $pdo = new PDO(
        $dsn,
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    $dbTz = getenv('DB_TIMEZONE') ?: '+08:00';
    $pdo->exec("SET time_zone = '" . str_replace("'", "", $dbTz) . "'");

    return $pdo;
}
