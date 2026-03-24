<?php
declare(strict_types=1);

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

$config = require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/helpers/MailService.php';
require __DIR__ . '/helpers/Ga4Service.php';
require __DIR__ . '/helpers/GoogleAdsService.php';


function write_log(string $message, array $context = []): void
{
    global $config;
    $line = sprintf("[%s] %s %s\n", date('Y-m-d H:i:s'), $message, $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '');
    error_log($line, 3, $config['logging']['path']);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $input = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);

    $apiKey = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ($input['api_key'] ?? '')));
    if ($apiKey === '') {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Missing API_KEY'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $siteStmt = db()->prepare('SELECT id, site_name, domain FROM sites WHERE api_key = :api_key LIMIT 1');
    $siteStmt->execute([':api_key' => $apiKey]);
    $site = $siteStmt->fetch();
    if (!$site) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Invalid API_KEY'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $formId = (int) ($input['form_id'] ?? 0);
    $name = trim((string) ($input['name'] ?? ''));
    $email = trim((string) ($input['email'] ?? ''));
    $phone = trim((string) ($input['phone'] ?? '')) ?: null;
    $message = trim((string) ($input['message'] ?? '')) ?: null;
    $gclid = trim((string) ($input['gclid'] ?? '')) ?: null;
    $wbraid = trim((string) ($input['wbraid'] ?? '')) ?: null;
    $gbraid = trim((string) ($input['gbraid'] ?? '')) ?: null;
    $clientIdInput = trim((string) ($input['client_id'] ?? '')) ?: null;

    if ($formId <= 0 || $name === '' || $email === '') {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Missing required fields'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Invalid email format'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $formStmt = db()->prepare('SELECT id, site_id, require_gclid, enable_ga4, enable_ads, enable_enhanced_conversion FROM forms WHERE id = :id LIMIT 1');
    $formStmt->execute([':id' => $formId]);
    $form = $formStmt->fetch();

    if (!$form || (int) $form['site_id'] !== (int) $site['id']) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Form does not belong to site'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $gclidRequiredButMissing = ((int) $form['require_gclid'] === 1 && ($gclid === null || $gclid === '') && ($wbraid === null || $wbraid === '') && ($gbraid === null || $gbraid === ''));
    if ($gclidRequiredButMissing) {
        write_log('submit missing gclid while require_gclid enabled', [
            'site_id' => (int) $site['id'],
            'form_id' => $formId,
            'source_channel' => (string) ($input['source_channel'] ?? ''),
            'landing_page' => (string) ($input['landing_page'] ?? ''),
        ]);
    }

    $inquiryParams = [
        ':site_id' => (int) $site['id'],
        ':form_id' => $formId,
        ':name' => $name,
        ':email' => $email,
        ':phone' => $phone,
        ':message' => $message,
        ':user_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ':gclid' => $gclid,
        ':wbraid' => $wbraid,
        ':gbraid' => $gbraid,
        ':client_id' => $clientIdInput,
        ':source_channel' => trim((string) ($input['source_channel'] ?? '')) ?: null,
        ':source_platform' => trim((string) ($input['source_platform'] ?? '')) ?: null,
        ':source_medium' => trim((string) ($input['source_medium'] ?? '')) ?: null,
        ':referrer_url' => trim((string) ($input['referrer_url'] ?? '')) ?: null,
        ':landing_page' => trim((string) ($input['landing_page'] ?? '')) ?: null,
        ':utm_source' => trim((string) ($input['utm_source'] ?? '')) ?: null,
        ':utm_medium' => trim((string) ($input['utm_medium'] ?? '')) ?: null,
        ':utm_campaign' => trim((string) ($input['utm_campaign'] ?? '')) ?: null,
        ':utm_term' => trim((string) ($input['utm_term'] ?? '')) ?: null,
        ':utm_content' => trim((string) ($input['utm_content'] ?? '')) ?: null,
        ':fbclid' => trim((string) ($input['fbclid'] ?? '')) ?: null,
    ];

    $insertSql = 'INSERT INTO inquiries
      (site_id, form_id, name, email, phone, message, user_ip, user_agent, gclid, wbraid, gbraid, client_id,
       source_channel, source_platform, source_medium, referrer_url, landing_page,
       utm_source, utm_medium, utm_campaign, utm_term, utm_content, fbclid, created_at)
      VALUES
      (:site_id, :form_id, :name, :email, :phone, :message, :user_ip, :user_agent, :gclid, :wbraid, :gbraid, :client_id,
       :source_channel, :source_platform, :source_medium, :referrer_url, :landing_page,
       :utm_source, :utm_medium, :utm_campaign, :utm_term, :utm_content, :fbclid, NOW())';

    try {
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

    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $safePhone = htmlspecialchars((string) $phone, ENT_QUOTES, 'UTF-8');
    $safeMessage = nl2br(htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8'));
    $safeSourceChannel = htmlspecialchars((string) ($inquiryParams[':source_channel'] ?? ''), ENT_QUOTES, 'UTF-8');
    $safeSourcePlatform = htmlspecialchars((string) ($inquiryParams[':source_platform'] ?? ''), ENT_QUOTES, 'UTF-8');
    $safeUtmSource = htmlspecialchars((string) ($inquiryParams[':utm_source'] ?? ''), ENT_QUOTES, 'UTF-8');
    $safeUtmMedium = htmlspecialchars((string) ($inquiryParams[':utm_medium'] ?? ''), ENT_QUOTES, 'UTF-8');
    $safeUtmCampaign = htmlspecialchars((string) ($inquiryParams[':utm_campaign'] ?? ''), ENT_QUOTES, 'UTF-8');
    $safeLandingPage = htmlspecialchars((string) ($inquiryParams[':landing_page'] ?? ''), ENT_QUOTES, 'UTF-8');

    $mailHtml = '
    <div style="margin:0;padding:24px;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;line-height:1.6;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:760px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">
        <tr>
          <td style="background:#0f172a;color:#ffffff;padding:16px 20px;">
            <div style="font-size:18px;font-weight:700;">新询盘通知 #' . $inquiryId . '</div>
            <div style="font-size:12px;opacity:.9;margin-top:4px;">提交时间：' . date('Y-m-d H:i:s') . '</div>
          </td>
        </tr>
        <tr>
          <td style="padding:18px 20px 8px;">
            <div style="font-size:15px;font-weight:700;margin-bottom:8px;">基础信息</div>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;">
              <tr>
                <td style="width:120px;padding:8px 0;color:#6b7280;vertical-align:top;">姓名</td>
                <td style="padding:8px 0;">' . $safeName . '</td>
              </tr>
              <tr>
                <td style="padding:8px 0;color:#6b7280;vertical-align:top;">邮箱</td>
                <td style="padding:8px 0;">' . $safeEmail . '</td>
              </tr>
              <tr>
                <td style="padding:8px 0;color:#6b7280;vertical-align:top;">电话</td>
                <td style="padding:8px 0;">' . $safePhone . '</td>
              </tr>
              <tr>
                <td style="padding:8px 0;color:#6b7280;vertical-align:top;">内容</td>
                <td style="padding:8px 0;word-break:break-word;">' . $safeMessage . '</td>
              </tr>
            </table>
          </td>
        </tr>
        <tr>
          <td style="padding:8px 20px 20px;">
            <div style="height:1px;background:#e5e7eb;margin:8px 0 14px;"></div>
            <div style="font-size:15px;font-weight:700;margin-bottom:8px;">来源信息</div>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;">
              <tr>
                <td style="width:120px;padding:6px 0;color:#6b7280;vertical-align:top;">来源渠道</td>
                <td style="padding:6px 0;">' . $safeSourceChannel . '</td>
              </tr>
              <tr>
                <td style="padding:6px 0;color:#6b7280;vertical-align:top;">来源平台</td>
                <td style="padding:6px 0;">' . $safeSourcePlatform . '</td>
              </tr>
              <tr>
                <td style="padding:6px 0;color:#6b7280;vertical-align:top;">UTM Source</td>
                <td style="padding:6px 0;">' . $safeUtmSource . '</td>
              </tr>
              <tr>
                <td style="padding:6px 0;color:#6b7280;vertical-align:top;">UTM Medium</td>
                <td style="padding:6px 0;">' . $safeUtmMedium . '</td>
              </tr>
              <tr>
                <td style="padding:6px 0;color:#6b7280;vertical-align:top;">UTM Campaign</td>
                <td style="padding:6px 0;">' . $safeUtmCampaign . '</td>
              </tr>
              <tr>
                <td style="padding:6px 0;color:#6b7280;vertical-align:top;">Landing Page</td>
                <td style="padding:6px 0;word-break:break-all;">' . $safeLandingPage . '</td>
              </tr>
            </table>
          </td>
        </tr>
      </table>
    </div>';
    $mailOk = $mailService->send((int) $site['id'], '新询盘通知 #' . $inquiryId, $mailHtml);
    $mailStatus = $mailOk ? 'success' : 'failed';
    if (!$mailOk) {
        $mailError = trim($mailService->getLastError());
        $errors[] = $mailError !== '' ? ('mail failed: ' . $mailError) : 'mail failed';
    }

    if ((int) $form['enable_ga4'] === 1) {
        $clientId = trim((string) ($clientIdInput ?? ''));
        if ($clientId === '') {
            $clientId = 'anon.' . bin2hex(random_bytes(8)) . '.' . time();
        }
        $ga4Ok = $ga4Service->sendGenerateLead(
            (int) $site['id'],
            $clientId,
            $email,
            $gclid,
            0.0,
            'USD',
            [
                'wbraid' => $wbraid,
                'gbraid' => $gbraid,
                'source_channel' => (string) ($inquiryParams[':source_channel'] ?? ''),
                'source_platform' => (string) ($inquiryParams[':source_platform'] ?? ''),
                'source_medium' => (string) ($inquiryParams[':source_medium'] ?? ''),
                'utm_source' => (string) ($inquiryParams[':utm_source'] ?? ''),
                'utm_medium' => (string) ($inquiryParams[':utm_medium'] ?? ''),
                'utm_campaign' => (string) ($inquiryParams[':utm_campaign'] ?? ''),
                'utm_term' => (string) ($inquiryParams[':utm_term'] ?? ''),
                'utm_content' => (string) ($inquiryParams[':utm_content'] ?? ''),
                'page_location' => (string) ($inquiryParams[':landing_page'] ?? ''),
                'page_referrer' => (string) ($inquiryParams[':referrer_url'] ?? ''),
            ]
        );
        $ga4Status = $ga4Ok ? 'success' : 'failed';
        if (!$ga4Ok) {
            $ga4Error = trim($ga4Service->getLastError());
            $errors[] = $ga4Error !== '' ? ('ga4 failed: ' . $ga4Error) : 'ga4 failed';
        }
    }

    if ((int) $form['enable_ads'] === 1) {
        if (($gclid !== null && $gclid !== '') || ($wbraid !== null && $wbraid !== '') || ($gbraid !== null && $gbraid !== '')) {
            $adsOk = $adsService->sendConversion((int) $site['id'], $email, (string) ($gclid ?? ''), 0.0, 'USD', [
                'wbraid' => $wbraid,
                'gbraid' => $gbraid,
                'utm_source' => (string) ($inquiryParams[':utm_source'] ?? ''),
                'utm_medium' => (string) ($inquiryParams[':utm_medium'] ?? ''),
            ]);
            $adsStatus = $adsOk ? 'success' : 'failed';
            if (!$adsOk) {
                $errors[] = 'ads failed';
            }
        } else {
            $adsStatus = 'skipped';
        }
    }

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

    echo json_encode(['status' => 'success'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    write_log('submit api error', ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal Server Error'], JSON_UNESCAPED_UNICODE);
}
