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

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_token'], $csrf)) {
        $error = 'CSRF 校验失败。';
    } else {
        $formId = (int) ($_POST['form_id'] ?? 0);
        if ($formId <= 0) {
            $error = '无效的表单ID。';
        } else {
            try {
                $stmt = db()->prepare('DELETE FROM forms WHERE id = :id');
                $stmt->execute([':id' => $formId]);
                $message = '表单删除成功。';
            } catch (Throwable $e) {
                $error = '删除失败，可能存在关联询盘数据。';
            }
        }
    }
}

$perPage = 10;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$countStmt = db()->prepare('SELECT COUNT(*) AS c FROM forms');
$countStmt->execute();
$total = (int) ($countStmt->fetch()['c'] ?? 0);
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$sql = 'SELECT f.id, f.form_name, f.enable_ga4, f.enable_ads, f.enable_enhanced_conversion, f.require_gclid, f.created_at,
               s.site_name
        FROM forms f
        INNER JOIN sites s ON s.id = f.site_id
        ORDER BY f.id DESC
        LIMIT :limit OFFSET :offset';
$listStmt = db()->prepare($sql);
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$forms = $listStmt->fetchAll();
?>
<?php admin_ui_start('表单管理', 'forms'); ?>
<div class="container">
    <div class="page-head">
        <h2 class="page-title">表单列表</h2>
        <a class="btn btn-primary" href="/admin/form_create.php">+ 创建表单</a>
    </div>

    <?php if ($message !== ''): ?><div class="msg ok"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="msg err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <div class="panel table-wrap">
    <table class="table">
        <thead>
        <tr>
            <th>ID</th>
            <th>表单名称</th>
            <th>所属站点</th>
            <th>配置项</th>
            <th>创建时间</th>
            <th>操作</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$forms): ?>
            <tr><td colspan="6">暂无表单数据。</td></tr>
        <?php else: ?>
            <?php foreach ($forms as $form): ?>
                <tr>
                    <td><?= (int) $form['id'] ?></td>
                    <td><?= htmlspecialchars((string) $form['form_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) $form['site_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="text-muted">
                        enable_ga4: <?= (int) $form['enable_ga4'] ?><br>
                        enable_ads: <?= (int) $form['enable_ads'] ?><br>
                        enable_enhanced_conversion: <?= (int) $form['enable_enhanced_conversion'] ?><br>
                        require_gclid: <?= (int) $form['require_gclid'] ?>
                    </td>
                    <td><?= htmlspecialchars(admin_format_datetime((string) $form['created_at']), ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <a class="btn btn-success btn-sm" href="/admin/form_edit.php?id=<?= (int) $form['id'] ?>">编辑</a>
                        <form method="post" action="" style="display:inline" onsubmit="return confirm('确认删除该表单？');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="form_id" value="<?= (int) $form['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                            <button class="btn btn-danger btn-sm" type="submit">删除</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    </div>

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
