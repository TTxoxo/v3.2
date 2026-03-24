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

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrl = $scheme . '://' . $host;

$siteId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($siteId <= 0) {
    header('Location: /admin/sites.php');
    exit;
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
            $error = '站点名称和域名不能为空。';
        } else {
            try {
                $stmt = db()->prepare('UPDATE sites SET site_name = :site_name, domain = :domain WHERE id = :id');
                $stmt->execute([
                    ':site_name' => $siteName,
                    ':domain' => $domain,
                    ':id' => $siteId,
                ]);

                header('Location: /admin/sites.php');
                exit;
            } catch (Throwable $e) {
                $error = '更新失败，请检查域名是否重复。';
            }
        }
    }
}

$stmt = db()->prepare('SELECT id, site_name, domain, api_key, created_at FROM sites WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $siteId]);
$site = $stmt->fetch();
if (!$site) {
    header('Location: /admin/sites.php');
    exit;
}

$embedFloating = '<script>' . "\n"
    . '(function(){' . "\n"
    . ' var s=document.createElement("script");' . "\n"
    . ' s.src="' . $baseUrl . '/embed/embed.js?key=' . $site['api_key'] . '";' . "\n"
    . ' document.body.appendChild(s);' . "\n"
    . '})();' . "\n"
    . '</script>';

$embedInline = '<div id="inquiry-inline-container"></div>' . "\n"
    . '<script>' . "\n"
    . '(function(){' . "\n"
    . ' var s=document.createElement("script");' . "\n"
    . ' s.src="' . $baseUrl . '/embed/embed.js?key=' . $site['api_key'] . '&display=inline&target=%23inquiry-inline-container";' . "\n"
    . ' document.body.appendChild(s);' . "\n"
    . '})();' . "\n"
    . '</script>';

$embedCode = "/* Floating */\n" . $embedFloating . "\n\n/* Inline */\n" . $embedInline;
?>
<?php admin_ui_start('编辑站点', 'sites'); ?>
<style>
.page{max-width:980px;margin:0 auto}.field{margin-bottom:12px}.field label{font-size:13px;color:#374151;display:block;margin-bottom:6px}.field input,.field textarea{width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;box-sizing:border-box}.field textarea{min-height:200px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}.actions{display:flex;gap:10px;align-items:center}.btn{padding:10px 14px;border-radius:8px;border:0;cursor:pointer}.btn-primary{background:#2563eb;color:#fff}.btn-back{display:inline-block;text-decoration:none;background:#6b7280;color:#fff}
</style>
<div class="page panel">
    <?php if ($error !== ''): ?><div class="msg err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <form method="post" action="">
        <input type="hidden" name="id" value="<?= (int) $site['id'] ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

        <div class="field">
            <label>站点名称</label>
            <input type="text" name="site_name" value="<?= htmlspecialchars((string) $site['site_name'], ENT_QUOTES, 'UTF-8') ?>" required>
        </div>

        <div class="field">
            <label>域名</label>
            <input type="text" name="domain" value="<?= htmlspecialchars((string) $site['domain'], ENT_QUOTES, 'UTF-8') ?>" required>
        </div>

        <div class="field">
            <label>API_KEY（只读）</label>
            <input type="text" value="<?= htmlspecialchars((string) $site['api_key'], ENT_QUOTES, 'UTF-8') ?>" readonly>
        </div>

        <div class="field">
            <label>嵌入代码</label>
            <textarea readonly><?= htmlspecialchars($embedCode, ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>

        <div class="actions">
            <button class="btn btn-primary" type="submit">保存修改</button>
            <a class="btn btn-back" href="/admin/sites.php">返回</a>
        </div>
    </form>
</div>
<?php admin_ui_end(); ?>
