<?php
declare(strict_types=1);

session_start();

require __DIR__ . '/../config/database.php';

if (empty($_SESSION['admin_user_id'])) {
    header('Location: /admin/login.php');
    exit;
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function generateApiKey(PDO $pdo): string
{
    do {
        $apiKey = bin2hex(random_bytes(16)); // 32位
        $stmt = $pdo->prepare('SELECT id FROM sites WHERE api_key = :api_key LIMIT 1');
        $stmt->execute([':api_key' => $apiKey]);
        $exists = (bool) $stmt->fetch();
    } while ($exists);

    return $apiKey;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_token'], $csrf)) {
        $error = 'CSRF 校验失败。';
    } else {
        $siteName = trim((string) ($_POST['site_name'] ?? ''));
        $domain = trim((string) ($_POST['domain'] ?? ''));

        if ($siteName === '' || $domain === '') {
            $error = '站点名称与域名不能为空。';
        } else {
            try {
                $apiKey = generateApiKey(db());
                $stmt = db()->prepare('INSERT INTO sites (site_name, domain, api_key, created_at) VALUES (:site_name, :domain, :api_key, NOW())');
                $stmt->execute([
                    ':site_name' => $siteName,
                    ':domain' => $domain,
                    ':api_key' => $apiKey,
                ]);

                header('Location: /admin/sites.php');
                exit;
            } catch (Throwable $e) {
                $error = '创建失败，请检查域名是否重复。';
            }
        }
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>创建站点</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f3f4f6;margin:0}
        .container{max-width:520px;margin:40px auto;background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,.06)}
        input{width:100%;padding:10px;border:1px solid #d1d5db;border-radius:6px;box-sizing:border-box;margin-bottom:12px}
        button,.back{padding:8px 12px;border-radius:6px;border:0;text-decoration:none;display:inline-block}
        button{background:#2563eb;color:#fff;cursor:pointer}
        .back{background:#6b7280;color:#fff}
        .err{background:#fee2e2;color:#991b1b;padding:10px;border-radius:6px;margin-bottom:12px}
    </style>
</head>
<body>
<div class="container">
    <h2>创建站点</h2>

    <?php if ($error !== ''): ?><div class="err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <form method="post" action="">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <label>站点名称</label>
        <input type="text" name="site_name" required>

        <label>域名</label>
        <input type="text" name="domain" placeholder="https://example.com" required>

        <button type="submit">创建</button>
        <a class="back" href="/admin/sites.php">返回</a>
    </form>
</div>
</body>
</html>
