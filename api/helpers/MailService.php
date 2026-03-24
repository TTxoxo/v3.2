<?php
declare(strict_types=1);

namespace Api\Helpers;

use PDO;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

class MailService
{
    private PDO $pdo;
    private string $logPath;
    private string $lastError = '';

    public function __construct(PDO $pdo, string $logPath)
    {
        $this->pdo = $pdo;
        $this->logPath = $logPath;
    }

    public function send(int $siteId, string $subject, string $html): bool
    {
        $this->lastError = '';
        try {
            $smtp = $this->getGlobalSmtp();
            if ($smtp === null) {
                $this->lastError = 'smtp_settings not found';
                $this->writeLog('MailService smtp_settings not found');
                return false;
            }

            $to = $this->getSiteToEmail($siteId);
            if ($to === null || $to === '') {
                $this->lastError = 'site smtp_to_email missing';
                $this->writeLog('MailService site smtp_to_email missing', ['site_id' => $siteId]);
                return false;
            }

            $toList = $this->parseRecipientList($to);
            $this->writeLog('MailService recipients parsed', ['site_id' => $siteId, 'count' => count($toList), 'recipients' => $toList]);
            if ($toList === []) {
                $this->lastError = 'recipient list invalid';
                $this->writeLog('MailService recipient list invalid', ['site_id' => $siteId, 'raw_to' => $to]);
                return false;
            }

            $host = (string) ($smtp['host'] ?? '');
            $port = (int) ($smtp['port'] ?? 0);
            $username = (string) ($smtp['username'] ?? '');
            $password = (string) ($smtp['password'] ?? '');
            $fromEmail = (string) ($smtp['from_email'] ?? '');
            $encryption = strtolower((string) ($smtp['encryption'] ?? 'tls'));

            if ($fromEmail === '' && $username !== '') {
                $fromEmail = $username;
            }

            if ($host === '' || $port <= 0 || $fromEmail === '') {
                $this->lastError = 'smtp params incomplete';
                $this->writeLog('MailService smtp params incomplete');
                return false;
            }

            if (!$this->bootPhpMailer()) {
                $this->lastError = 'PHPMailer not available, SMTP delivery disabled';
                $this->writeLog('MailService PHPMailer not available, SMTP delivery disabled', ['site_id' => $siteId]);
                return false;
            }

            $mailer = new PHPMailer(true);
            $mailer->isSMTP();
            $mailer->Host = $host;
            $mailer->Port = $port;
            $mailer->SMTPAuth = ($username !== '' && $password !== '');
            if ($mailer->SMTPAuth) {
                $mailer->Username = $username;
                $mailer->Password = $password;
            }
            $mailer->CharSet = 'UTF-8';
            $mailer->Timeout = 20;
            $mailer->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ];

            if ($encryption === 'ssl') {
                $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($encryption === 'tls') {
                $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mailer->SMTPSecure = '';
                $mailer->SMTPAutoTLS = false;
            }

            if ($mailer->SMTPAuth && $username !== '' && strcasecmp($fromEmail, $username) !== 0) {
                $this->writeLog('MailService from_email differs from smtp_username; provider may show sender on behalf of account', [
                    'site_id' => $siteId,
                    'from_email' => $fromEmail,
                    'smtp_username' => $username,
                ]);
            }

            $mailer->setFrom($fromEmail, 'Inquiry System', false);
            $mailer->Sender = $fromEmail;
            foreach ($toList as $recipient) {
                $mailer->addAddress($recipient);
            }
            $mailer->isHTML(true);
            $mailer->Subject = $subject;
            $mailer->Body = $html;
            $mailer->AltBody = trim(strip_tags($html)) ?: 'inquiry notification';

            $ok = $mailer->send();
            if (!$ok) {
                $this->lastError = trim((string) $mailer->ErrorInfo) ?: 'send failed';
            }
            return $ok;
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            $this->writeLog('MailService PHPMailer exception: ' . $e->getMessage());
            return false;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->writeLog('MailService error: ' . $e->getMessage());
            return false;
        }
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    private function getGlobalSmtp(): ?array
    {
        $stmt = $this->pdo->prepare('SELECT host,port,username,password,encryption,from_email FROM smtp_settings ORDER BY id DESC LIMIT 1');
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function getSiteToEmail(int $siteId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT smtp_to_email FROM site_settings WHERE site_id = :site_id LIMIT 1');
        $stmt->execute([':site_id' => $siteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $to = trim((string) ($row['smtp_to_email'] ?? ''));
        return $to === '' ? null : $to;
    }

    private function writeLog(string $message, array $context = []): void
    {
        $line = sprintf("[%s] %s %s\n", date('Y-m-d H:i:s'), $message, $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '');
        error_log($line, 3, $this->logPath);
    }

    private function parseRecipientList(string $raw): array
    {
        $normalized = str_replace(["；", "，", "、", "|"], [";", ",", ",", ","], $raw);
        $parts = preg_split('/[;,\r\n\t ]+/', $normalized) ?: [];

        $list = [];
        foreach ($parts as $part) {
            $email = trim((string) $part);
            if ($email === '') {
                continue;
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $list[] = strtolower($email);
            }
        }

        return array_values(array_unique($list));
    }

    private function bootPhpMailer(): bool
    {
        if (class_exists(PHPMailer::class)) {
            return true;
        }

        $autoload = __DIR__ . '/../../vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }

        if (class_exists(PHPMailer::class)) {
            return true;
        }

        $phpMailerBase = __DIR__ . '/../../vendor/phpmailer/phpmailer/src/';
        $files = ['Exception.php', 'PHPMailer.php', 'SMTP.php'];
        foreach ($files as $file) {
            $path = $phpMailerBase . $file;
            if (is_file($path)) {
                require_once $path;
            }
        }

        return class_exists(PHPMailer::class);
    }

    private function sendWithNativeMail(string $fromEmail, array $toList, string $subject, string $html): bool
    {
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: ' . $fromEmail,
        ];

        $ok = @mail(implode(',', $toList), $subject, $html, implode("\r\n", $headers));
        if (!$ok) {
            $this->lastError = 'native mail() failed';
            $this->writeLog('MailService native mail() failed', ['to' => $toList]);
        }
        return $ok;
    }
}
