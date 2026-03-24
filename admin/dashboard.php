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
<div class="panel" style="margin-bottom:12px">
    <div style="font-size:20px;font-weight:700">欢迎，<?= htmlspecialchars((string)$admin['username'], ENT_QUOTES, 'UTF-8') ?></div>
    <div style="font-size:12px;color:#64748b">管理员ID：<?= (int)$admin['id'] ?> ｜ 创建时间：<?= htmlspecialchars(admin_format_datetime((string)$admin['created_at']), ENT_QUOTES, 'UTF-8') ?></div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
    <a class="panel" style="text-decoration:none;color:#0f172a" href="/admin/sites.php"><div style="font-weight:700;margin-bottom:6px">站点管理</div><div style="font-size:12px;color:#64748b">站点创建、编辑、API Key 与嵌入代码</div></a>
    <a class="panel" style="text-decoration:none;color:#0f172a" href="/admin/forms.php"><div style="font-weight:700;margin-bottom:6px">表单管理</div><div style="font-size:12px;color:#64748b">字段配置、必填、排序、转化开关</div></a>
    <a class="panel" style="text-decoration:none;color:#0f172a" href="/admin/site_settings.php"><div style="font-weight:700;margin-bottom:6px">配置中心</div><div style="font-size:12px;color:#64748b">按站点配置 GA4/Ads 与收件邮箱，统一配置全站 SMTP</div></a>
    <a class="panel" style="text-decoration:none;color:#0f172a" href="/admin/inquiries.php"><div style="font-weight:700;margin-bottom:6px">询盘管理</div><div style="font-size:12px;color:#64748b">筛选、详情、状态、CSV导出</div></a>
</div>
<?php admin_ui_end(); ?>
