<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

$requestOrigin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
if ($requestOrigin !== '') {
    header('Access-Control-Allow-Origin: ' . $requestOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-API-KEY');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $input = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);

    $required = ['site_id', 'form_id', 'name', 'email'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => "Missing field: {$field}"], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $params = [
        ':site_id' => (int) $input['site_id'],
        ':form_id' => (int) $input['form_id'],
        ':name' => trim((string) $input['name']),
        ':email' => trim((string) $input['email']),
        ':phone' => isset($input['phone']) ? trim((string) $input['phone']) : null,
        ':message' => isset($input['message']) ? trim((string) $input['message']) : null,
        ':user_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ':gclid' => isset($input['gclid']) ? trim((string) $input['gclid']) : null,
        ':wbraid' => isset($input['wbraid']) ? trim((string) $input['wbraid']) : null,
        ':gbraid' => isset($input['gbraid']) ? trim((string) $input['gbraid']) : null,
        ':client_id' => isset($input['client_id']) ? trim((string) $input['client_id']) : null,
        ':source_channel' => isset($input['source_channel']) ? trim((string) $input['source_channel']) : null,
        ':source_platform' => isset($input['source_platform']) ? trim((string) $input['source_platform']) : null,
        ':source_medium' => isset($input['source_medium']) ? trim((string) $input['source_medium']) : null,
        ':referrer_url' => isset($input['referrer_url']) ? trim((string) $input['referrer_url']) : null,
        ':landing_page' => isset($input['landing_page']) ? trim((string) $input['landing_page']) : null,
        ':utm_source' => isset($input['utm_source']) ? trim((string) $input['utm_source']) : null,
        ':utm_medium' => isset($input['utm_medium']) ? trim((string) $input['utm_medium']) : null,
        ':utm_campaign' => isset($input['utm_campaign']) ? trim((string) $input['utm_campaign']) : null,
        ':utm_term' => isset($input['utm_term']) ? trim((string) $input['utm_term']) : null,
        ':utm_content' => isset($input['utm_content']) ? trim((string) $input['utm_content']) : null,
        ':fbclid' => isset($input['fbclid']) ? trim((string) $input['fbclid']) : null,
    ];

    $sql = 'INSERT INTO inquiries (site_id, form_id, name, email, phone, message, user_ip, user_agent, gclid, wbraid, gbraid, client_id,
              source_channel, source_platform, source_medium, referrer_url, landing_page,
              utm_source, utm_medium, utm_campaign, utm_term, utm_content, fbclid, created_at)
            VALUES (:site_id, :form_id, :name, :email, :phone, :message, :user_ip, :user_agent, :gclid, :wbraid, :gbraid, :client_id,
              :source_channel, :source_platform, :source_medium, :referrer_url, :landing_page,
              :utm_source, :utm_medium, :utm_campaign, :utm_term, :utm_content, :fbclid, NOW())';

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S22' && stripos($e->getMessage(), 'Unknown column') === false) {
            throw $e;
        }

        $fallbackSql = 'INSERT INTO inquiries (site_id, form_id, name, email, phone, message, user_ip, user_agent, gclid, created_at)
                        VALUES (:site_id, :form_id, :name, :email, :phone, :message, :user_ip, :user_agent, :gclid, NOW())';
        $fallbackStmt = db()->prepare($fallbackSql);
        $fallbackStmt->execute([
            ':site_id' => $params[':site_id'],
            ':form_id' => $params[':form_id'],
            ':name' => $params[':name'],
            ':email' => $params[':email'],
            ':phone' => $params[':phone'],
            ':message' => $params[':message'],
            ':user_ip' => $params[':user_ip'],
            ':user_agent' => $params[':user_agent'],
            ':gclid' => $params[':gclid'],
        ]);
    }

    echo json_encode([
        'success' => true,
        'inquiry_id' => (int) db()->lastInsertId(),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log(sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $e->getMessage()), 3, $config['logging']['path']);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal Server Error'], JSON_UNESCAPED_UNICODE);
}
