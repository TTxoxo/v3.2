<?php
declare(strict_types=1);

$config = require __DIR__ . '/config/config.php';
require __DIR__ . '/config/database.php';

date_default_timezone_set($config['app']['timezone']);

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $e) use ($config): void {
    $logLine = sprintf(
        "[%s] %s in %s:%d\n%s\n\n",
        date('Y-m-d H:i:s'),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );

    error_log($logLine, 3, $config['logging']['path']);

    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'success' => false,
        'message' => $config['app']['debug'] ? $e->getMessage() : 'Internal Server Error',
    ], JSON_UNESCAPED_UNICODE);
});

register_shutdown_function(static function () use ($config): void {
    $error = error_get_last();
    if ($error === null) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array($error['type'], $fatalTypes, true)) {
        return;
    }

    $logLine = sprintf(
        "[%s] FATAL: %s in %s:%d\n\n",
        date('Y-m-d H:i:s'),
        $error['message'],
        $error['file'],
        $error['line']
    );

    error_log($logLine, 3, $config['logging']['path']);
});

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/health') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'ok', 'time' => date('c')], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'message' => 'Foreign Trade Inquiry Manager API',
    'routes' => ['/health', '/api/get_form.php', '/api/submit.php'],
], JSON_UNESCAPED_UNICODE);
