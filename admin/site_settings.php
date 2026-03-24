<?php
declare(strict_types=1);

session_start();

require __DIR__ . '/../config/database.php';
require __DIR__ . '/_ui.php';

if (empty($_SESSION['admin_user_id'])) {
    header('Location: /admin/login.php');
    exit;
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function send_smtp_test_mail(array $smtp, string $to, string &$errorMsg = ''): bool
{
    $errorMsg = '';

    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }

    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        $base = __DIR__ . '/../vendor/phpmailer/phpmailer/src/';
        foreach (['Exception.php', 'PHPMailer.php', 'SMTP.php'] as $file) {
            $path = $base . $file;
            if (is_file($path)) {
                require_once $path;
            }
        }
    }

    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        $errorMsg = 'PHPMailer 未安装，请将 PHPMailer 源码复制到 vendor/phpmailer/phpmailer/src/ 目录。';
        return false;
    }

    try {
        $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = (string) ($smtp['host'] ?? '');
        $mailer->Port = (int) ($smtp['port'] ?? 0);
        $mailer->SMTPAuth = ((string) ($smtp['username'] ?? '') !== '' && (string) ($smtp['password'] ?? '') !== '');
        if ($mailer->SMTPAuth) {
            $mailer->Username = (string) ($smtp['username'] ?? '');
            $mailer->Password = (string) ($smtp['password'] ?? '');
        }

        $encryption = strtolower((string) ($smtp['encryption'] ?? 'tls'));
        if ($encryption === 'ssl') {
            $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls') {
            $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mailer->SMTPSecure = '';
            $mailer->SMTPAutoTLS = false;
        }

        $from = trim((string) ($smtp['from_email'] ?? ''));
        if ($from === '') {
            $from = trim((string) ($smtp['username'] ?? ''));
        }
        if ($from === '') {
            $errorMsg = 'From Email 不能为空。';
            return false;
        }

        $mailer->CharSet = 'UTF-8';
        $mailer->Timeout = 20;
        $mailer->setFrom($from, 'Inquiry System Test', false);
        $mailer->Sender = $from;
        $mailer->addAddress($to);
        $mailer->isHTML(true);
        $mailer->Subject = 'SMTP 测试邮件 - 外贸询盘系统';
        $mailer->Body = '<div style="font-family:Arial,sans-serif"><h3>SMTP 测试成功</h3><p>这是一封来自外贸询盘系统的测试邮件。</p><p>发送时间：' . htmlspecialchars(date('Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8') . '</p></div>';
        $mailer->AltBody = 'SMTP 测试成功。';

        return $mailer->send();
    } catch (\Throwable $e) {
        $errorMsg = $e->getMessage();
        return false;
    }
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_token'], $csrf)) {
        $error = 'CSRF 校验失败';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'save_smtp') {
            $smtpData = [
                ':host' => trim((string) ($_POST['smtp_host'] ?? '')),
                ':port' => (int) ($_POST['smtp_port'] ?? 0),
                ':username' => trim((string) ($_POST['smtp_username'] ?? '')),
                ':password' => trim((string) ($_POST['smtp_password'] ?? '')),
                ':encryption' => trim((string) ($_POST['smtp_encryption'] ?? 'tls')),
                ':from_email' => trim((string) ($_POST['smtp_from_email'] ?? '')),
            ];

            $existsGlobal = db()->prepare('SELECT id FROM smtp_settings ORDER BY id DESC LIMIT 1');
            $existsGlobal->execute();
            $globalRow = $existsGlobal->fetch();

            if ($globalRow) {
                $sql = 'UPDATE smtp_settings SET
                        host = :host,
                        port = :port,
                        username = :username,
                        password = :password,
                        encryption = :encryption,
                        from_email = :from_email
                        WHERE id = :id';
                $stmt = db()->prepare($sql);
                $stmt->execute($smtpData + [':id' => (int) $globalRow['id']]);
            } else {
                $sql = 'INSERT INTO smtp_settings
                        (host, port, username, password, encryption, from_email, created_at)
                        VALUES
                        (:host, :port, :username, :password, :encryption, :from_email, NOW())';
                $stmt = db()->prepare($sql);
                $stmt->execute($smtpData);
            }

            $message = '全站 SMTP 配置已保存';
        }

        if ($action === 'test_smtp') {
            $smtpTest = [
                'host' => trim((string) ($_POST['smtp_host'] ?? '')),
                'port' => (int) ($_POST['smtp_port'] ?? 0),
                'username' => trim((string) ($_POST['smtp_username'] ?? '')),
                'password' => trim((string) ($_POST['smtp_password'] ?? '')),
                'encryption' => trim((string) ($_POST['smtp_encryption'] ?? 'tls')),
                'from_email' => trim((string) ($_POST['smtp_from_email'] ?? '')),
            ];
            $testTo = trim((string) ($_POST['smtp_test_to_email'] ?? ''));

            if ($smtpTest['host'] === '' || $smtpTest['port'] <= 0 || $testTo === '') {
                $error = '请先填写 SMTP 参数和测试收件邮箱';
            } elseif (!filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
                $error = '测试收件邮箱格式不正确';
            } else {
                $testErr = '';
                $ok = send_smtp_test_mail($smtpTest, $testTo, $testErr);
                if ($ok) {
                    $message = 'SMTP 测试邮件发送成功，请检查邮箱：' . $testTo;
                } else {
                    $error = 'SMTP 测试失败：' . ($testErr !== '' ? $testErr : '未知错误');
                }
            }
        }
    }
}

$smtp = [
    'host' => '',
    'port' => 587,
    'username' => '',
    'password' => '',
    'encryption' => 'tls',
    'from_email' => '',
];

$smtpTestToEmail = trim((string) ($_POST['smtp_test_to_email'] ?? ''));

$smtpStmt = db()->prepare('SELECT host, port, username, password, encryption, from_email FROM smtp_settings ORDER BY id DESC LIMIT 1');
$smtpStmt->execute();
$smtpRow = $smtpStmt->fetch();
if ($smtpRow) {
    $smtp = array_merge($smtp, $smtpRow);
}
?>
<?php admin_ui_start('配置中心', 'settings'); ?>
<style>
.container{max-width:1100px;margin:0 auto;display:grid;grid-template-columns:1fr;gap:14px}.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}@media (max-width:900px){.grid{grid-template-columns:1fr}.full{grid-column:auto}}label{font-size:13px;color:#4b5563;display:block;margin-bottom:6px}input,select{width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;box-sizing:border-box}h3{margin:6px 0 10px;font-size:18px}button{background:#2563eb;color:#fff;border:0;border-radius:8px;padding:10px 14px;cursor:pointer}.full{grid-column:1/3}
</style>
<div class="container">
<?php if ($message !== ''): ?><div class="msg ok"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="msg err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

<div class="card">
<form method="post">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
<h3>全站 SMTP 配置（统一）</h3>
<div class="grid">
<div><label>SMTP Host</label><input name="smtp_host" value="<?= htmlspecialchars((string)$smtp['host'], ENT_QUOTES, 'UTF-8') ?>"></div>
<div><label>SMTP Port</label><input type="number" name="smtp_port" value="<?= (int)$smtp['port'] ?>"></div>
<div><label>SMTP Username</label><input name="smtp_username" value="<?= htmlspecialchars((string)$smtp['username'], ENT_QUOTES, 'UTF-8') ?>"></div>
<div><label>SMTP Password</label><input name="smtp_password" value="<?= htmlspecialchars((string)$smtp['password'], ENT_QUOTES, 'UTF-8') ?>"></div>
<div><label>SMTP Encryption</label><select name="smtp_encryption"><option value="none" <?= $smtp['encryption']==='none'?'selected':'' ?>>none</option><option value="ssl" <?= $smtp['encryption']==='ssl'?'selected':'' ?>>ssl</option><option value="tls" <?= $smtp['encryption']==='tls'?'selected':'' ?>>tls</option></select></div>
<div><label>From Email</label><input name="smtp_from_email" value="<?= htmlspecialchars((string)$smtp['from_email'], ENT_QUOTES, 'UTF-8') ?>"><small style="color:#6b7280">建议与 SMTP Username 一致，否则部分服务商会显示“由 xxx 代发”。</small></div>
<div class="full"><label>测试收件邮箱</label><input name="smtp_test_to_email" placeholder="test@example.com" value="<?= htmlspecialchars($smtpTestToEmail !== '' ? $smtpTestToEmail : (string)$smtp['from_email'], ENT_QUOTES, 'UTF-8') ?>"></div>
</div>
<p style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">
  <button type="submit" name="action" value="save_smtp">保存全站 SMTP</button>
  <button type="submit" name="action" value="test_smtp" style="background:#0ea5e9">发送测试邮件</button>
</p>
</form>
</div>
</div>
<?php admin_ui_end(); ?>
