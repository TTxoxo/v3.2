<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-API-KEY');

$config = require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/helpers/MailService.php';
require __DIR__ . '/helpers/Ga4Service.php';
require __DIR__ . '/helpers/GoogleAdsService.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function write_log(string $message, array $context = []): void
{
    global $config;
    $line = sprintf(
        "[%s] %s %s\n",
        date('Y-m-d H:i:s'),
        $message,
        $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
    );
    error_log($line, 3, $config['logging']['path']);
}

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

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

function is_allowed_origin(string $originHostNorm, string $siteHostNorm): bool
{
    if ($originHostNorm === '' || $siteHostNorm === '') {
        return false;
    }

    return $originHostNorm === $siteHostNorm
        || str_ends_with($originHostNorm, '.' . $siteHostNorm);
}

function table_exists(string $table): bool
{
    $stmt = db()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1');
    $stmt->execute([':table' => $table]);
    return (bool) $stmt->fetchColumn();
}

function load_form_fields(int $formId, string $fieldsJson): array
{
    if (table_exists('form_fields')) {
        $stmt = db()->prepare('SELECT field_key, field_label, field_type, is_builtin, is_required, sort_order
                               FROM form_fields
                               WHERE form_id = :form_id AND is_active = 1
                               ORDER BY sort_order ASC, id ASC');
        $stmt->execute([':form_id' => $formId]);
        $rows = $stmt->fetchAll();

        if (is_array($rows) && count($rows) > 0) {
            return array_map(static function (array $row): array {
                return [
                    'key' => (string) $row['field_key'],
                    'label' => (string) $row['field_label'],
                    'type' => strtolower((string) $row['field_type']),
                    'is_builtin' => (int) $row['is_builtin'] === 1,
                    'required' => (int) $row['is_required'] === 1,
                ];
            }, $rows);
        }
    }

    $legacy = json_decode($fieldsJson, true);
    if (!is_array($legacy)) {
        $legacy = [];
    }

    $mapped = [];
    foreach ($legacy as $idx => $field) {
        $name = strtolower(trim((string) ($field['name'] ?? ('field_' . ($idx + 1)))));
        if ($name === 'phone' || $name === 'mobile') {
            $name = 'tel';
        }

        $mapped[] = [
            'key' => $name,
            'label' => trim((string) ($field['label'] ?? $name)),
            'type' => strtolower(trim((string) ($field['type'] ?? 'text'))),
            'is_builtin' => in_array($name, ['name', 'tel', 'email', 'message'], true),
            'required' => !empty($field['required']),
        ];
    }

    $builtinDefaults = [
        'name' => ['label' => 'Name', 'type' => 'text', 'required' => true],
        'tel' => ['label' => 'Tel', 'type' => 'phone', 'required' => false],
        'email' => ['label' => 'Email', 'type' => 'email', 'required' => true],
        'message' => ['label' => 'Message', 'type' => 'textarea', 'required' => false],
    ];

    $existing = array_column($mapped, 'key');
    foreach ($builtinDefaults as $key => $meta) {
        if (!in_array($key, $existing, true)) {
            $mapped[] = [
                'key' => $key,
                'label' => $meta['label'],
                'type' => $meta['type'],
                'is_builtin' => true,
                'required' => $meta['required'],
            ];
        }
    }

    return $mapped;
}

function read_scalar_value(array $input, string $key): string
{
    if (!array_key_exists($key, $input)) {
        return '';
    }

    $value = $input[$key];
    if (is_array($value) || is_object($value)) {
        return '';
    }

    return trim((string) $value);
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        respond(405, ['status' => 'error', 'message' => 'Method Not Allowed']);
    }

    $rawBody = file_get_contents('php://input') ?: '';
    if (strlen($rawBody) > 50 * 1024) {
        respond(413, ['status' => 'error', 'message' => 'Payload too large']);
    }

    $input = json_decode($rawBody !== '' ? $rawBody : '{}', true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        respond(422, ['status' => 'error', 'message' => 'Invalid JSON payload']);
    }

    $apiKey = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ($input['api_key'] ?? '')));
    if ($apiKey === '') {
        respond(422, ['status' => 'error', 'message' => 'Missing API_KEY']);
    }

    $siteStmt = db()->prepare('SELECT id, site_name, domain FROM sites WHERE api_key = :api_key LIMIT 1');
    $siteStmt->execute([':api_key' => $apiKey]);
    $site = $siteStmt->fetch();
    if (!$site) {
        respond(401, ['status' => 'error', 'message' => 'Invalid API_KEY']);
    }

    $originHostNorm = normalize_host(extract_origin_host());
    $siteHostNorm = normalize_host(site_host((string) $site['domain']));

    if (!is_allowed_origin($originHostNorm, $siteHostNorm)) {
        write_log('submit blocked by origin validation', [
            'site_id' => (int) $site['id'],
            'origin_host' => $originHostNorm,
            'site_host' => $siteHostNorm,
        ]);
        respond(403, ['status' => 'error', 'message' => 'Origin domain not allowed']);
    }

    header('Access-Control-Allow-Origin: ' . (string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    header('Vary: Origin');

    $formId = (int) ($input['form_id'] ?? 0);
    if ($formId <= 0) {
        respond(422, ['status' => 'error', 'message' => 'Missing form_id']);
    }

    $formStmt = db()->prepare('SELECT id, site_id, fields_json, require_gclid, enable_ga4, enable_ads, enable_enhanced_conversion
                               FROM forms WHERE id = :id LIMIT 1');
    $formStmt->execute([':id' => $formId]);
    $form = $formStmt->fetch();

    if (!$form || (int) $form['site_id'] !== (int) $site['id']) {
        respond(403, ['status' => 'error', 'message' => 'Form does not belong to site']);
    }

    $siteIdInput = (int) ($input['site_id'] ?? 0);
    if ($siteIdInput > 0 && $siteIdInput !== (int) $site['id']) {
        respond(403, ['status' => 'error', 'message' => 'site_id does not match API_KEY site']);
    }

    if (read_scalar_value($input, 'company_website') !== '' || read_scalar_value($input, 'website') !== '') {
        respond(422, ['status' => 'error', 'message' => 'Spam detected']);
    }

    $userIp = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($userIp !== '') {
        $rateStmt = db()->prepare('SELECT COUNT(*) FROM inquiries WHERE site_id = :site_id AND user_ip = :user_ip AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)');
        $rateStmt->execute([':site_id' => (int) $site['id'], ':user_ip' => $userIp]);
        $recentCount = (int) $rateStmt->fetchColumn();
        if ($recentCount >= 15) {
            write_log('submit rate limit exceeded', ['site_id' => (int) $site['id'], 'ip' => $userIp]);
            respond(429, ['status' => 'error', 'message' => 'Too many requests, please retry later']);
        }
    }

    $definitions = load_form_fields((int) $form['id'], (string) ($form['fields_json'] ?? '[]'));
    $definitionMap = [];
    foreach ($definitions as $def) {
        $definitionMap[(string) $def['key']] = $def;
    }

    $builtinKeys = ['name', 'tel', 'email', 'message'];
    foreach ($builtinKeys as $k) {
        if (!isset($definitionMap[$k])) {
            $definitionMap[$k] = [
                'key' => $k,
                'label' => ucfirst($k),
                'type' => $k === 'email' ? 'email' : ($k === 'message' ? 'textarea' : 'text'),
                'is_builtin' => true,
                'required' => in_array($k, ['name', 'email'], true),
            ];
        }
    }

    $builtinValues = [
        'name' => read_scalar_value($input, 'name'),
        'tel' => read_scalar_value($input, 'tel'),
        'email' => read_scalar_value($input, 'email'),
        'message' => read_scalar_value($input, 'message'),
    ];

    if ($builtinValues['tel'] === '') {
        $builtinValues['tel'] = read_scalar_value($input, 'phone');
    }

    foreach ($definitionMap as $key => $def) {
        if (!empty($def['is_builtin']) && $builtinValues[$key] === '' && read_scalar_value($input, $key) !== '') {
            $builtinValues[$key] = read_scalar_value($input, $key);
        }

        if (!empty($def['required']) && !empty($def['is_builtin']) && $builtinValues[$key] === '') {
            respond(422, ['status' => 'error', 'message' => 'Missing required field: ' . $key]);
        }
    }

    if ($builtinValues['name'] === '' || $builtinValues['email'] === '') {
        respond(422, ['status' => 'error', 'message' => 'Missing required builtin fields']);
    }

    if (!filter_var($builtinValues['email'], FILTER_VALIDATE_EMAIL)) {
        respond(422, ['status' => 'error', 'message' => 'Invalid email format']);
    }

    if ($builtinValues['tel'] !== '' && !preg_match('/^[0-9\-\+\s\(\)\.]{5,40}$/', $builtinValues['tel'])) {
        respond(422, ['status' => 'error', 'message' => 'Invalid tel format']);
    }

    $customPayload = [];
    foreach ($definitionMap as $key => $def) {
        if (!empty($def['is_builtin'])) {
            continue;
        }

        $value = read_scalar_value($input, $key);
        if (!empty($def['required']) && $value === '') {
            respond(422, ['status' => 'error', 'message' => 'Missing required field: ' . $key]);
        }

        if ($value !== '') {
            $customPayload[$key] = $value;
        }
    }

    $tracking = [
        'gclid' => read_scalar_value($input, 'gclid') ?: null,
        'wbraid' => read_scalar_value($input, 'wbraid') ?: null,
        'gbraid' => read_scalar_value($input, 'gbraid') ?: null,
        'client_id' => read_scalar_value($input, 'client_id') ?: null,
        'source_channel' => read_scalar_value($input, 'source_channel') ?: null,
        'source_platform' => read_scalar_value($input, 'source_platform') ?: null,
        'source_medium' => read_scalar_value($input, 'source_medium') ?: null,
        'referrer_url' => read_scalar_value($input, 'referrer_url') ?: null,
        'landing_page' => read_scalar_value($input, 'landing_page') ?: null,
        'utm_source' => read_scalar_value($input, 'utm_source') ?: null,
        'utm_medium' => read_scalar_value($input, 'utm_medium') ?: null,
        'utm_campaign' => read_scalar_value($input, 'utm_campaign') ?: null,
        'utm_term' => read_scalar_value($input, 'utm_term') ?: null,
        'utm_content' => read_scalar_value($input, 'utm_content') ?: null,
        'fbclid' => read_scalar_value($input, 'fbclid') ?: null,
    ];

    $gclidRequiredButMissing = ((int) $form['require_gclid'] === 1
        && ($tracking['gclid'] === null || $tracking['gclid'] === '')
        && ($tracking['wbraid'] === null || $tracking['wbraid'] === '')
        && ($tracking['gbraid'] === null || $tracking['gbraid'] === ''));

    if ($gclidRequiredButMissing) {
        write_log('submit missing gclid while require_gclid enabled', [
            'site_id' => (int) $site['id'],
            'form_id' => $formId,
            'source_channel' => (string) ($tracking['source_channel'] ?? ''),
        ]);
    }

    $inquiryParams = [
        ':site_id' => (int) $site['id'],
        ':form_id' => $formId,
        ':name' => $builtinValues['name'],
        ':tel' => $builtinValues['tel'] !== '' ? $builtinValues['tel'] : null,
        ':email' => $builtinValues['email'],
        ':message' => $builtinValues['message'] !== '' ? $builtinValues['message'] : null,
        ':phone' => $builtinValues['tel'] !== '' ? $builtinValues['tel'] : null,
        ':payload_json' => json_encode($customPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':user_ip' => $userIp !== '' ? $userIp : null,
        ':user_agent' => trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')) ?: null,
        ':gclid' => $tracking['gclid'],
        ':wbraid' => $tracking['wbraid'],
        ':gbraid' => $tracking['gbraid'],
        ':client_id' => $tracking['client_id'],
        ':source_channel' => $tracking['source_channel'],
        ':source_platform' => $tracking['source_platform'],
        ':source_medium' => $tracking['source_medium'],
        ':referrer_url' => $tracking['referrer_url'],
        ':landing_page' => $tracking['landing_page'],
        ':utm_source' => $tracking['utm_source'],
        ':utm_medium' => $tracking['utm_medium'],
        ':utm_campaign' => $tracking['utm_campaign'],
        ':utm_term' => $tracking['utm_term'],
        ':utm_content' => $tracking['utm_content'],
        ':fbclid' => $tracking['fbclid'],
    ];

    try {
        $insertSql = 'INSERT INTO inquiries
          (site_id, form_id, name, tel, email, message, payload_json, phone, user_ip, user_agent, gclid, wbraid, gbraid, client_id,
           source_channel, source_platform, source_medium, referrer_url, landing_page,
           utm_source, utm_medium, utm_campaign, utm_term, utm_content, fbclid, created_at)
          VALUES
          (:site_id, :form_id, :name, :tel, :email, :message, :payload_json, :phone, :user_ip, :user_agent, :gclid, :wbraid, :gbraid, :client_id,
           :source_channel, :source_platform, :source_medium, :referrer_url, :landing_page,
           :utm_source, :utm_medium, :utm_campaign, :utm_term, :utm_content, :fbclid, NOW())';

        $insertStmt = db()->prepare($insertSql);
        $insertStmt->execute($inquiryParams);
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S22' && stripos($e->getMessage(), 'Unknown column') === false) {
            throw $e;
        }

        $fallbackSql = 'INSERT INTO inquiries
          (site_id, form_id, name, email, phone, message, user_ip, user_agent, gclid, created_at)
          VALUES
          (:site_id, :form_id, :name, :email, :phone, :message, :user_ip, :user_agent, :gclid, NOW())';
        $fallbackStmt = db()->prepare($fallbackSql);
        $fallbackStmt->execute([
            ':site_id' => $inquiryParams[':site_id'],
            ':form_id' => $inquiryParams[':form_id'],
            ':name' => $inquiryParams[':name'],
            ':email' => $inquiryParams[':email'],
            ':phone' => $inquiryParams[':phone'],
            ':message' => $inquiryParams[':message'],
            ':user_ip' => $inquiryParams[':user_ip'],
            ':user_agent' => $inquiryParams[':user_agent'],
            ':gclid' => $inquiryParams[':gclid'],
        ]);
    }

    $inquiryId = (int) db()->lastInsertId();

    $mailService = new \Api\Helpers\MailService(db(), $config['logging']['path']);
    $ga4Service = new \Api\Helpers\Ga4Service(db(), $config['logging']['path']);
    $adsService = new \Api\Helpers\GoogleAdsService(db(), $config['logging']['path']);

    $mailStatus = 'skipped';
    $ga4Status = 'skipped';
    $adsStatus = 'skipped';
    $errors = [];

    $safeName = htmlspecialchars($builtinValues['name'], ENT_QUOTES, 'UTF-8');
    $safeEmail = htmlspecialchars($builtinValues['email'], ENT_QUOTES, 'UTF-8');
    $safeTel = htmlspecialchars((string) $builtinValues['tel'], ENT_QUOTES, 'UTF-8');
    $safeMessage = nl2br(htmlspecialchars((string) $builtinValues['message'], ENT_QUOTES, 'UTF-8'));

    $mailHtml = '
    <div style="margin:0;padding:24px;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;line-height:1.6;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:760px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">
        <tr><td style="background:#0f172a;color:#ffffff;padding:16px 20px;"><div style="font-size:18px;font-weight:700;">新询盘通知 #' . $inquiryId . '</div></td></tr>
        <tr><td style="padding:18px 20px 8px;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;">
          <tr><td style="width:120px;padding:8px 0;color:#6b7280;">姓名</td><td style="padding:8px 0;">' . $safeName . '</td></tr>
          <tr><td style="padding:8px 0;color:#6b7280;">邮箱</td><td style="padding:8px 0;">' . $safeEmail . '</td></tr>
          <tr><td style="padding:8px 0;color:#6b7280;">电话</td><td style="padding:8px 0;">' . $safeTel . '</td></tr>
          <tr><td style="padding:8px 0;color:#6b7280;">内容</td><td style="padding:8px 0;word-break:break-word;">' . $safeMessage . '</td></tr>
        </table></td></tr>
      </table>
    </div>';

    try {
        $mailOk = $mailService->send((int) $site['id'], '新询盘通知 #' . $inquiryId, $mailHtml);
        $mailStatus = $mailOk ? 'success' : 'failed';
        if (!$mailOk) {
            $errors[] = 'mail failed';
        }
    } catch (Throwable $mailEx) {
        $mailStatus = 'failed';
        $errors[] = 'mail failed';
        write_log('submit integration mail failed', ['inquiry_id' => $inquiryId, 'reason' => $mailEx->getMessage()]);
    }

    if ((int) $form['enable_ga4'] === 1) {
        try {
            $clientId = (string) ($tracking['client_id'] ?? '');
            if ($clientId === '') {
                $clientId = 'anon.' . bin2hex(random_bytes(8)) . '.' . time();
            }

            $ga4Ok = $ga4Service->sendGenerateLead(
                (int) $site['id'],
                $clientId,
                $builtinValues['email'],
                (string) ($tracking['gclid'] ?? ''),
                0.0,
                'USD',
                [
                    'wbraid' => $tracking['wbraid'],
                    'gbraid' => $tracking['gbraid'],
                    'source_channel' => $tracking['source_channel'] ?? '',
                    'source_platform' => $tracking['source_platform'] ?? '',
                    'source_medium' => $tracking['source_medium'] ?? '',
                    'utm_source' => $tracking['utm_source'] ?? '',
                    'utm_medium' => $tracking['utm_medium'] ?? '',
                    'utm_campaign' => $tracking['utm_campaign'] ?? '',
                    'utm_term' => $tracking['utm_term'] ?? '',
                    'utm_content' => $tracking['utm_content'] ?? '',
                    'page_location' => $tracking['landing_page'] ?? '',
                    'page_referrer' => $tracking['referrer_url'] ?? '',
                ]
            );
            $ga4Status = $ga4Ok ? 'success' : 'failed';
            if (!$ga4Ok) {
                $errors[] = 'ga4 failed';
            }
        } catch (Throwable $ga4Ex) {
            $ga4Status = 'failed';
            $errors[] = 'ga4 failed';
            write_log('submit integration ga4 failed', ['inquiry_id' => $inquiryId, 'reason' => $ga4Ex->getMessage()]);
        }
    }

    if ((int) $form['enable_ads'] === 1) {
        try {
            if (($tracking['gclid'] ?? '') !== '' || ($tracking['wbraid'] ?? '') !== '' || ($tracking['gbraid'] ?? '') !== '') {
                $adsOk = $adsService->sendConversion((int) $site['id'], $builtinValues['email'], (string) ($tracking['gclid'] ?? ''), 0.0, 'USD', [
                    'wbraid' => $tracking['wbraid'],
                    'gbraid' => $tracking['gbraid'],
                    'utm_source' => $tracking['utm_source'] ?? '',
                    'utm_medium' => $tracking['utm_medium'] ?? '',
                ]);
                $adsStatus = $adsOk ? 'success' : 'failed';
                if (!$adsOk) {
                    $errors[] = 'ads failed';
                }
            } else {
                $adsStatus = 'skipped';
            }
        } catch (Throwable $adsEx) {
            $adsStatus = 'failed';
            $errors[] = 'ads failed';
            write_log('submit integration ads failed', ['inquiry_id' => $inquiryId, 'reason' => $adsEx->getMessage()]);
        }
    }

    try {
        $logStmt = db()->prepare('INSERT INTO form_logs
          (inquiry_id, ga4_status, ads_status, mail_status, error_message, created_at)
          VALUES
          (:inquiry_id, :ga4_status, :ads_status, :mail_status, :error_message, NOW())');
        $logStmt->execute([
            ':inquiry_id' => $inquiryId,
            ':ga4_status' => $ga4Status,
            ':ads_status' => $adsStatus,
            ':mail_status' => $mailStatus,
            ':error_message' => $errors ? implode(' | ', $errors) : null,
        ]);
    } catch (Throwable $logEx) {
        write_log('submit integration log write failed', ['inquiry_id' => $inquiryId, 'reason' => $logEx->getMessage()]);
    }

    respond(200, [
        'status' => 'success',
        'success' => true,
        'inquiry_id' => $inquiryId,
    ]);
} catch (JsonException $e) {
    write_log('submit invalid json', ['reason' => $e->getMessage()]);
    respond(422, ['status' => 'error', 'message' => 'Invalid JSON payload']);
} catch (Throwable $e) {
    write_log('submit api error', ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    respond(500, ['status' => 'error', 'message' => 'Internal Server Error']);
}
