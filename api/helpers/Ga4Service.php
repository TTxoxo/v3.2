<?php
declare(strict_types=1);

namespace Api\Helpers;

use PDO;

class Ga4Service
{
    private PDO $pdo;
    private string $logPath;
    private string $lastError = '';

    public function __construct(PDO $pdo, string $logPath)
    {
        $this->pdo = $pdo;
        $this->logPath = $logPath;
    }

    public function sendGenerateLead(
        int $siteId,
        string $clientId,
        ?string $email = null,
        ?string $gclid = null,
        float $value = 0.0,
        string $currency = 'USD',
        array $extraParams = []
    ): bool {
        $this->lastError = '';
        try {
            $settings = $this->getSettingsBySite($siteId);
            if ($settings === null) {
                $this->lastError = 'settings not found';
                $this->writeLog('Ga4Service: settings not found', ['site_id' => $siteId]);
                return false;
            }

            $measurementId = trim((string) ($settings['ga4_measurement_id'] ?? ''));
            $apiSecret = trim((string) ($settings['ga4_api_secret'] ?? ''));
            if ($measurementId === '' || $apiSecret === '') {
                $this->lastError = 'measurement_id or api_secret missing';
                $this->writeLog('Ga4Service: ga4 params missing', ['site_id' => $siteId]);
                return false;
            }

            if (trim($clientId) === '') {
                $this->lastError = 'client_id missing';
                $this->writeLog('Ga4Service: client_id missing', ['site_id' => $siteId]);
                return false;
            }

            $endpoint = sprintf(
                'https://www.google-analytics.com/mp/collect?measurement_id=%s&api_secret=%s',
                rawurlencode($measurementId),
                rawurlencode($apiSecret)
            );
            $fallbackEndpoint = sprintf(
                'https://region1.google-analytics.com/mp/collect?measurement_id=%s&api_secret=%s',
                rawurlencode($measurementId),
                rawurlencode($apiSecret)
            );

            $eventParams = ['currency' => strtoupper($currency), 'value' => $value];
            if ($gclid !== null && trim($gclid) !== '') {
                $eventParams['gclid'] = trim($gclid);
            }

            $allowedExtra = [
                'wbraid', 'gbraid',
                'source_channel', 'source_platform', 'source_medium',
                'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
                'page_location', 'page_referrer'
            ];
            foreach ($allowedExtra as $key) {
                $val = trim((string) ($extraParams[$key] ?? ''));
                if ($val !== '') {
                    $eventParams[$key] = $val;
                }
            }

            $payload = [
                'client_id' => trim($clientId),
                'events' => [['name' => 'generate_lead', 'params' => $eventParams]],
            ];

            if ($email !== null && trim($email) !== '') {
                $payload['user_properties'] = ['email' => ['value' => $this->hashEmail($email)]];
            }

            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($body === false) {
                $this->lastError = 'json encode failed';
                return false;
            }

            $primary = $this->postJson($endpoint, $body);
            if ($primary['ok']) {
                return true;
            }

            $fallback = $this->postJson($fallbackEndpoint, $body);
            if ($fallback['ok']) {
                $this->writeLog('Ga4Service fallback endpoint used', ['site_id' => $siteId]);
                return true;
            }

            $this->lastError = sprintf(
                'primary http=%d err=%s; fallback http=%d err=%s',
                (int) $primary['http_code'],
                (string) $primary['error'],
                (int) $fallback['http_code'],
                (string) $fallback['error']
            );
            $this->writeLog('Ga4Service request failed', [
                'site_id' => $siteId,
                'primary' => $primary,
                'fallback' => $fallback,
            ]);
            return false;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->writeLog('Ga4Service error: ' . $e->getMessage(), ['site_id' => $siteId]);
            return false;
        }
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    private function getSettingsBySite(int $siteId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ga4_measurement_id, ga4_api_secret FROM site_settings WHERE site_id = :site_id LIMIT 1');
        $stmt->execute([':site_id' => $siteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }

        $fallback = $this->pdo->prepare('SELECT ga4_measurement_id, ga4_api_secret FROM google_settings ORDER BY id DESC LIMIT 1');
        $fallback->execute();
        $global = $fallback->fetch(PDO::FETCH_ASSOC);
        return $global ?: null;
    }

    private function hashEmail(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email), 'UTF-8'));
    }

    private function writeLog(string $message, array $context = []): void
    {
        $line = sprintf("[%s] %s %s\n", date('Y-m-d H:i:s'), $message, $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '');
        error_log($line, 3, $this->logPath);
    }

    private function postJson(string $endpoint, string $body): array
    {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'ok' => $response !== false && $code >= 200 && $code < 300,
            'http_code' => $code,
            'error' => $err,
            'response' => is_string($response) ? $response : '',
        ];
    }
}
