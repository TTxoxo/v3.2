<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60, s-maxage=120');

$requestOrigin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
if ($requestOrigin !== '') {
    header('Access-Control-Allow-Origin: ' . $requestOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-API-KEY');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require __DIR__ . '/../config/database.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => 'Method Not Allowed',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $apiKey = trim((string) ($_GET['key'] ?? ''));
    if ($apiKey === '') {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Missing API_KEY',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $siteStmt = db()->prepare('SELECT id, site_name, domain, api_key FROM sites WHERE api_key = :api_key LIMIT 1');
    $siteStmt->execute([':api_key' => $apiKey]);
    $site = $siteStmt->fetch();

    if (!$site) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid API_KEY',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $originHost = '';
    $originHeader = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($originHeader !== '') {
        $originHost = (string) (parse_url($originHeader, PHP_URL_HOST) ?: '');
    } else {
        $refererHeader = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        if ($refererHeader !== '') {
            $originHost = (string) (parse_url($refererHeader, PHP_URL_HOST) ?: '');
        }
    }

    $siteHost = (string) (parse_url((string) $site['domain'], PHP_URL_HOST) ?: '');
    if ($siteHost === '') {
        $siteHost = preg_replace('#^https?://#i', '', (string) $site['domain']);
        $siteHost = explode('/', (string) $siteHost)[0] ?? '';
    }

    $normalizeHost = static function (string $host): string {
        $h = strtolower(trim($host));
        if (str_starts_with($h, 'www.')) {
            $h = substr($h, 4);
        }
        return $h;
    };

    $originHostNorm = $normalizeHost($originHost);
    $siteHostNorm = $normalizeHost($siteHost);

    $isAllowedOrigin = true;
    if ($originHostNorm !== '' && $siteHostNorm !== '') {
        $isAllowedOrigin = $originHostNorm === $siteHostNorm
            || str_ends_with($originHostNorm, '.' . $siteHostNorm)
            || str_ends_with($siteHostNorm, '.' . $originHostNorm);
    }

    if (!$isAllowedOrigin) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Origin domain not allowed',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $formStmt = db()->prepare('SELECT id, site_id, form_name, fields_json, enable_ga4, enable_ads, enable_enhanced_conversion, require_gclid, created_at
                               FROM forms
                               WHERE site_id = :site_id
                               ORDER BY id DESC
                               LIMIT 1');
    $formStmt->execute([':site_id' => (int) $site['id']]);
    $form = $formStmt->fetch();

    if (!$form) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Form not found',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $fields = json_decode((string) $form['fields_json'], true);
    if (!is_array($fields)) {
        $fields = [];
    }

    $response = [
        'success' => true,
        'message' => 'ok',
        'data' => [
            'site_id' => (int) $form['site_id'],
            'form_id' => (int) $form['id'],
            'form_name' => (string) $form['form_name'],
            'fields' => $fields,
            'enable_ga4' => (int) $form['enable_ga4'],
            'enable_ads' => (int) $form['enable_ads'],
            'enable_enhanced_conversion' => (int) $form['enable_enhanced_conversion'],
            'require_gclid' => (int) $form['require_gclid'],
        ],
    ];

    $etag = 'W/"' . sha1((string) $form['id'] . '|' . (string) $form['created_at'] . '|' . (string) $form['fields_json']) . '"';
    header('ETag: ' . $etag);

    $ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
        http_response_code(304);
        exit;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal Server Error',
    ], JSON_UNESCAPED_UNICODE);
}
