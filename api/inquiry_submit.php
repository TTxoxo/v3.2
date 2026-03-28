<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-API-KEY');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

http_response_code(410);
echo json_encode([
    'success' => false,
    'status' => 'error',
    'message' => 'Deprecated endpoint. Use /api/submit.php as the only official public submit endpoint.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
