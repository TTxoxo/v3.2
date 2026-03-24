<?php
declare(strict_types=1);

session_start();
$config = require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/database.php';

date_default_timezone_set($config['app']['timezone']);

$systemName = '外贸询盘系统';
$loginName = '询盘系统后台';
$loginTitle = 'H5 扁平化管理界面';
try {
    $stmtSettings = db()->prepare('SELECT system_name, admin_login_name, admin_login_title FROM system_settings ORDER BY id DESC LIMIT 1');
    $stmtSettings->execute();
    $settingsRow = $stmtSettings->fetch();
    if (is_array($settingsRow)) {
        $systemName = trim((string) ($settingsRow['system_name'] ?? '')) ?: $systemName;
        $loginName = trim((string) ($settingsRow['admin_login_name'] ?? '')) ?: $loginName;
        $loginTitle = trim((string) ($settingsRow['admin_login_title'] ?? '')) ?: $loginTitle;
    }
} catch (Throwable $e) {
    // ignore missing table in old deployments
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
}
if (!empty($_SESSION['admin_user_id'])) {
    header('Location: /admin/dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_token'], $csrf)) {
        $error = '非法请求，请刷新后重试。';
    } elseif ($_SESSION['login_attempts'] >= 5) {
        $error = '登录失败次数过多，请稍后再试。';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $stmt = db()->prepare('SELECT id, username, password FROM admin_users WHERE username = :username LIMIT 1');
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, (string) $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['admin_user_id'] = (int) $user['id'];
            $_SESSION['admin_username'] = (string) $user['username'];
            $_SESSION['login_attempts'] = 0;
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            header('Location: /admin/dashboard.php');
            exit;
        }

        $_SESSION['login_attempts']++;
        $remaining = max(0, 5 - (int) $_SESSION['login_attempts']);
        $error = $remaining > 0 ? "用户名或密码错误，剩余尝试 {$remaining} 次。" : '登录失败次数过多，请稍后再试。';
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($loginName, ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@latest/dist/css/tabler.min.css">
<style>
*{box-sizing:border-box}
body{margin:0;background:radial-gradient(circle at top,#e8f0ff 0%,#f8faff 46%,#f3f6fb 100%);font-family:Inter,"PingFang SC","Microsoft YaHei",Arial,sans-serif;color:#111827}
.page{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
.card{width:100%;max-width:440px;background:#fff;border:1px solid #e5e9f2;border-radius:16px;padding:30px;box-shadow:0 12px 36px rgba(15,23,42,.08)}
.logo{display:inline-flex;align-items:center;gap:8px;padding:4px 10px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:12px;margin-bottom:10px}
h1{margin:0 0 4px;font-size:25px;font-weight:700;color:#0f172a}.sub{margin:0 0 20px;color:#64748b;font-size:13px}
label{display:block;font-size:13px;color:#344054;margin:12px 0 6px;font-weight:600}
input{width:100%;padding:11px 12px;border:1px solid #d4dbe7;border-radius:10px;outline:none}
input:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.15)}
button{width:100%;margin-top:16px;padding:11px;border:0;border-radius:10px;background:#206bc4;color:#fff;font-weight:600;cursor:pointer}
button:hover{background:#1a5aa8}
.error{margin-top:12px;padding:10px;border-radius:10px;background:#fff1f2;color:#9f1239;font-size:13px;border:1px solid #fecdd3}
.hint{margin-top:12px;color:#6b7280;font-size:12px}
.system{margin-top:4px;color:#94a3b8;font-size:12px}
</style>
</head>
<body>
<div class="page"><div class="card">
<div class="logo">🚀 <?= htmlspecialchars($systemName, ENT_QUOTES, 'UTF-8') ?></div>
<h1><?= htmlspecialchars($loginName, ENT_QUOTES, 'UTF-8') ?></h1>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
<label>用户名</label><input type="text" name="username" required>
<label>密码</label><input type="password" name="password" required>
<button type="submit">登录后台</button>
</form>
<?php if ($error !== ''): ?><div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<p class="system">System: <?= htmlspecialchars($systemName, ENT_QUOTES, 'UTF-8') ?></p>
</div></div>
</body>
</html>
