<?php
declare(strict_types=1);

namespace Api\Helpers;

use PDO;

class GoogleAdsService
{
    private PDO $pdo;
    private string $logPath;

    public function __construct(PDO $pdo, string $logPath)
    {
        $this->pdo = $pdo;
        $this->logPath = $logPath;
    }

    public function sendConversion(int $siteId, string $email, string $gclid, float $value = 0.0, string $currency = 'USD', array $extra = []): bool
    {
        try {
            $settings = $this->getSettingsBySite($siteId);
            if ($settings === null) {
                $this->writeLog('GoogleAdsService settings not found', ['site_id' => $siteId]);
                return false;
            }

            $conversionId = trim((string) ($settings['ads_conversion_id'] ?? ''));
            $conversionLabel = trim((string) ($settings['ads_conversion_label'] ?? ''));
            if ($conversionId === '' || $conversionLabel === '') {
                $this->writeLog('GoogleAdsService conversion params missing', ['site_id' => $siteId]);
                return false;
            }

            $email = trim($email);
            $gclid = trim($gclid);
            $wbraid = trim((string) ($extra['wbraid'] ?? ''));
            $gbraid = trim((string) ($extra['gbraid'] ?? ''));
            if ($email === '' || ($gclid === '' && $wbraid === '' && $gbraid === '')) {
                return false;
            }

            $endpoint = 'https://www.googleadservices.com/pagead/conversion/' . rawurlencode($conversionId) . '/';
            $payload = [
                'conversion_id' => $conversionId,
                'conversion_label' => $conversionLabel,
                'gclid' => $gclid,
                'wbraid' => $wbraid !== '' ? $wbraid : null,
                'gbraid' => $gbraid !== '' ? $gbraid : null,
                'value' => $value,
                'currency_code' => strtoupper($currency),
                'email' => hash('sha256', mb_strtolower($email, 'UTF-8')),
            ];

            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

            if ($response === false || $code < 200 || $code >= 300) {
                $this->writeLog('GoogleAdsService request failed', ['site_id' => $siteId, 'http_code' => $code, 'error' => $err, 'response' => $response, 'hint' => 'recommend migrate to official Google Ads API for production-grade enhanced conversions']);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->writeLog('GoogleAdsService error: ' . $e->getMessage(), ['site_id' => $siteId]);
            return false;
        }
    }

    private function getSettingsBySite(int $siteId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ads_conversion_id, ads_conversion_label FROM site_settings WHERE site_id = :site_id LIMIT 1');
        $stmt->execute([':site_id' => $siteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
        $fallback = $this->pdo->prepare('SELECT ads_conversion_id, ads_conversion_label FROM google_settings ORDER BY id DESC LIMIT 1');
        $fallback->execute();
        $global = $fallback->fetch(PDO::FETCH_ASSOC);
        return $global ?: null;
    }

    private function writeLog(string $message, array $context = []): void
    {
        $line = sprintf("[%s] %s %s\n", date('Y-m-d H:i:s'), $message, $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '');
        error_log($line, 3, $this->logPath);
    }
}
