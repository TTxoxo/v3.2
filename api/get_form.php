<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60, s-maxage=120');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-API-KEY');
header('Vary: Origin');

function send_get_form_preflight_headers(): void
{
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin !== '') {
        header('Access-Control-Allow-Origin: ' . $origin);
    }

    $requestedHeaders = trim((string) ($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? ''));
    if ($requestedHeaders !== '') {
        header('Access-Control-Allow-Headers: ' . $requestedHeaders);
    } else {
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-API-KEY');
    }

    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Max-Age: 600');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    send_get_form_preflight_headers();
    http_response_code(204);
    exit;
}

require __DIR__ . '/../config/database.php';

function normalize_host(string $host): string
{
    $h = strtolower(trim($host));
    if ($h === '') {
        return '';
    }
    if (str_starts_with($h, 'www.')) {
        $h = substr($h, 4);
    }
    return $h;
}

function extract_origin_host(): string
{
    $originHeader = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($originHeader !== '') {
        return (string) (parse_url($originHeader, PHP_URL_HOST) ?: '');
    }

    $refererHeader = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
    if ($refererHeader !== '') {
        return (string) (parse_url($refererHeader, PHP_URL_HOST) ?: '');
    }

    return '';
}

function site_host(string $domain): string
{
    $host = (string) (parse_url($domain, PHP_URL_HOST) ?: '');
    if ($host !== '') {
        return $host;
    }

    $normalized = preg_replace('#^https?://#i', '', trim($domain));
    return explode('/', (string) $normalized)[0] ?? '';
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $apiKey = trim((string) ($_GET['key'] ?? ''));
    if ($apiKey === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Missing API_KEY'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $siteStmt = db()->prepare('SELECT id, site_name, domain, api_key FROM sites WHERE api_key = :api_key LIMIT 1');
    $siteStmt->execute([':api_key' => $apiKey]);
    $site = $siteStmt->fetch();

    if (!$site) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid API_KEY'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $originHostNorm = normalize_host(extract_origin_host());
    $siteHostNorm = normalize_host(site_host((string) $site['domain']));
    $isAllowedOrigin = $originHostNorm !== '' && $siteHostNorm !== ''
        && ($originHostNorm === $siteHostNorm || str_ends_with($originHostNorm, '.' . $siteHostNorm));

    if (!$isAllowedOrigin) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Origin domain not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Access-Control-Allow-Origin: ' . (string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    header('Vary: Origin');

    $formStmt = db()->prepare('SELECT id, site_id, form_name, fields_json, enable_ga4, enable_ads, enable_enhanced_conversion, require_gclid, created_at
                               FROM forms
                               WHERE site_id = :site_id
                               ORDER BY id DESC
                               LIMIT 1');
    $formStmt->execute([':site_id' => (int) $site['id']]);
    $form = $formStmt->fetch();

    if (!$form) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Form not found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $fields = [];
    try {
        $fieldStmt = db()->prepare('SELECT field_key, field_label, field_type, is_required, sort_order
                                    FROM form_fields
                                    WHERE form_id = :form_id AND is_active = 1
                                    ORDER BY sort_order ASC, id ASC');
        $fieldStmt->execute([':form_id' => (int) $form['id']]);
        $fieldRows = $fieldStmt->fetchAll();

        if (is_array($fieldRows) && count($fieldRows) > 0) {
            foreach ($fieldRows as $f) {
                $fields[] = [
                    'name' => (string) $f['field_key'],
                    'label' => (string) $f['field_label'],
                    'type' => (string) $f['field_type'],
                    'required' => (int) $f['is_required'] === 1,
                    'sort' => (int) $f['sort_order'],
                ];
            }
        }
    } catch (Throwable $e) {
        // Compatibility fallback for old deployments before form_fields exists.
    }

    if ($fields === []) {
        $legacy = json_decode((string) $form['fields_json'], true);
        if (is_array($legacy)) {
            $fields = $legacy;
        }
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

    $etag = 'W/"' . sha1((string) $form['id'] . '|' . (string) $form['created_at'] . '|' . json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"';
    header('ETag: ' . $etag);

    $ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
        http_response_code(304);
        exit;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal Server Error'], JSON_UNESCAPED_UNICODE);
}
