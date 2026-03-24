<?php
declare(strict_types=1);

session_start();

$config = require __DIR__ . '/../config/config.php';
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

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_token'], $csrf)) {
        $error = 'CSRF 校验失败。';
    } else {
        $siteId = (int) ($_POST['site_id'] ?? 0);
        if ($siteId <= 0) {
            $error = '无效的站点ID。';
        } else {
            try {
                $stmt = db()->prepare('DELETE FROM sites WHERE id = :id');
                $stmt->execute([':id' => $siteId]);
                $message = '站点删除成功。';
            } catch (Throwable $e) {
                $error = '站点删除失败，可能存在关联表单或询盘数据。';
            }
        }
    }
}

$perPage = 10;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$countStmt = db()->prepare('SELECT COUNT(*) AS c FROM sites');
$countStmt->execute();
$total = (int) ($countStmt->fetch()['c'] ?? 0);
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$listStmt = db()->prepare('SELECT id, site_name, domain, api_key, created_at FROM sites ORDER BY id DESC LIMIT :limit OFFSET :offset');
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$sites = $listStmt->fetchAll();
?>
<?php admin_ui_start('站点管理', 'sites'); ?>
<style>
.container{max-width:1100px;margin:0 auto}.btn{display:inline-block;padding:6px 10px;border-radius:6px;text-decoration:none;border:0;cursor:pointer}.btn-create{background:#2563eb;color:#fff}.btn-edit{background:#059669;color:#fff}.btn-del{background:#dc2626;color:#fff}
</style>
<div class="container">
    <a class="btn btn-create" href="/admin/site_create.php">+ 创建站点</a>

    <?php if ($message !== ''): ?><div class="msg ok"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="msg err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <table>
        <thead>
        <tr>
            <th>ID</th>
            <th>站点名称</th>
            <th>域名</th>
            <th>API_KEY</th>
            <th>嵌入代码</th>
            <th>创建时间</th>
            <th>操作</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$sites): ?>
            <tr><td colspan="7">暂无站点数据。</td></tr>
        <?php else: ?>
            <?php foreach ($sites as $site): ?>
                <?php
                $embed = "<script>\n(function(){\n var s=document.createElement(\"script\");\n s.src=\"" . $baseUrl . "/embed/embed.js?key=" . $site['api_key'] . "\";\n document.body.appendChild(s);\n})();\n</script>";
                ?>
                <tr>
                    <td><?= (int) $site['id'] ?></td>
                    <td><?= htmlspecialchars((string) $site['site_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) $site['domain'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><code><?= htmlspecialchars((string) $site['api_key'], ENT_QUOTES, 'UTF-8') ?></code></td>
                    <td><textarea readonly><?= htmlspecialchars($embed, ENT_QUOTES, 'UTF-8') ?></textarea></td>
                    <td><?= htmlspecialchars((string) $site['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <a class="btn btn-edit" href="/admin/site_edit.php?id=<?= (int) $site['id'] ?>">编辑</a>
                        <form method="post" action="" style="display:inline" onsubmit="return confirm('确认删除该站点？');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="site_id" value="<?= (int) $site['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                            <button class="btn btn-del" type="submit">删除</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <div class="pager">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <?php if ($i === $page): ?>
                <strong>[<?= $i ?>]</strong>
            <?php else: ?>
                <a href="?page=<?= $i ?>">第<?= $i ?>页</a>
            <?php endif; ?>
        <?php endfor; ?>
    </div>
</div>
<?php admin_ui_end(); ?>
