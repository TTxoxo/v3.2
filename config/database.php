<?php
declare(strict_types=1);

$phpTz = getenv('APP_TIMEZONE') ?: 'Asia/Shanghai';
if ($phpTz !== '') {
    date_default_timezone_set($phpTz);
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

    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_NAME') ?: 'wbsform';
    $user = getenv('DB_USER') ?: 'wbsform';
    $pass = getenv('DB_PASS') ?: 'pFhTNJBhcjj2bSrG';

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
