<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/../config/database.php';
require __DIR__ . '/_ui.php';

if (empty($_SESSION['admin_user_id'])) {
    header('Location: /admin/login.php');
    exit;
}

$stmt = db()->prepare('SELECT id, username, created_at FROM admin_users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => (int) $_SESSION['admin_user_id']]);
$admin = $stmt->fetch();
if (!$admin) {
    session_unset();
    session_destroy();
    header('Location: /admin/login.php');
    exit;
}
?>
<?php admin_ui_start('控制台', 'dashboard'); ?>
<div class="panel">
    <h2 class="panel-title">欢迎，<?= htmlspecialchars((string) $admin['username'], ENT_QUOTES, 'UTF-8') ?></h2>
    <div class="hint">管理员ID：<?= (int) $admin['id'] ?> ｜ 创建时间：<?= htmlspecialchars(admin_format_datetime((string) $admin['created_at']), ENT_QUOTES, 'UTF-8') ?></div>
</div>

<div class="quick-grid">
    <a class="quick-link panel" href="/admin/sites.php"><div class="title">站点管理</div><div class="desc">站点创建、编辑、API Key 与嵌入代码</div></a>
    <a class="quick-link panel" href="/admin/forms.php"><div class="title">表单管理</div><div class="desc">字段配置、必填、排序、转化开关</div></a>
    <a class="quick-link panel" href="/admin/site_settings.php"><div class="title">配置中心</div><div class="desc">按站点配置 GA4/Ads 与收件邮箱，统一配置全站 SMTP</div></a>
    <a class="quick-link panel" href="/admin/inquiries.php"><div class="title">询盘管理</div><div class="desc">筛选、详情、状态、CSV导出</div></a>
</div>
<?php admin_ui_end(); ?>
