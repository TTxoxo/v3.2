<?php
declare(strict_types=1);

session_start();

require __DIR__ . '/../config/database.php';
require __DIR__ . '/_ui.php';

if (empty($_SESSION['admin_user_id'])) {
    header('Location: /admin/login.php');
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: /admin/inquiries.php');
    exit;
}

$sql = 'SELECT i.*, s.site_name, s.domain, f.form_name,
               fl.ga4_status, fl.ads_status, fl.mail_status, fl.error_message, fl.created_at AS log_created_at
        FROM inquiries i
        INNER JOIN sites s ON s.id = i.site_id
        INNER JOIN forms f ON f.id = i.form_id
        LEFT JOIN form_logs fl ON fl.inquiry_id = i.id
        WHERE i.id = :id
        LIMIT 1';
$stmt = db()->prepare($sql);
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();

if (!$row) {
    header('Location: /admin/inquiries.php');
    exit;
}
?>
<?php admin_ui_start('询盘详情 #' . (int) $row['id'], 'inquiries'); ?>
<style>
    .container{max-width:920px;margin:0 auto}
    td{border-bottom:1px solid #e5e7eb;padding:10px;vertical-align:top}
    td:first-child{width:220px;background:#f9fafb;font-weight:bold}
    .status{padding:2px 8px;border-radius:999px;font-size:12px}
    .ok{background:#dcfce7;color:#166534}.fail{background:#fee2e2;color:#991b1b}.pending{background:#fef3c7;color:#92400e}
    pre{white-space:pre-wrap;margin:0}
</style>
<div class="container panel">
    <div style="margin-bottom:10px"><a class="text-blue-600" href="/admin/inquiries.php">← 返回询盘列表</a></div>
    <table>
        <tr><td>ID</td><td><?= (int) $row['id'] ?></td></tr>
        <tr><td>站点</td><td><?= htmlspecialchars((string) $row['site_name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars((string) $row['domain'], ENT_QUOTES, 'UTF-8') ?>)</td></tr>
        <tr><td>表单</td><td><?= htmlspecialchars((string) $row['form_name'], ENT_QUOTES, 'UTF-8') ?></td></tr>
        <tr><td>姓名</td><td><?= htmlspecialchars((string) $row['name'], ENT_QUOTES, 'UTF-8') ?></td></tr>
        <tr><td>邮箱</td><td><?= htmlspecialchars((string) $row['email'], ENT_QUOTES, 'UTF-8') ?></td></tr>
        <tr><td>电话</td><td><?= htmlspecialchars((string) ($row['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
        <tr><td>消息</td><td><pre><?= htmlspecialchars((string) ($row['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></pre></td></tr>
        <tr><td>GCLID</td><td><?= htmlspecialchars((string) ($row['gclid'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
        <tr><td>WBRAID</td><td><?= htmlspecialchars((string) ($row['wbraid'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
        <tr><td>GBRAID</td><td><?= htmlspecialchars((string) ($row['gbraid'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
        <tr><td>Client ID</td><td><?= htmlspecialchars((string) ($row['client_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
        <tr><td>来源渠道</td><td><?= htmlspecialchars((string) ($row['source_channel'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
        <tr><td>来源平台</td><td><?= htmlspecialchars((string) ($row['source_platform'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
        <tr><td>来源媒介</td><td><?= htmlspecialchars((string) ($row['source_medium'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
        <tr><td>Referrer</td><td><pre><?= htmlspecialchars((string) ($row['referrer_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?></pre></td></tr>
        <tr><td>Landing Page</td><td><pre><?= htmlspecialchars((string) ($row['landing_page'] ?? ''), ENT_QUOTES, 'UTF-8') ?></pre></td></tr>
        <tr><td>UTM</td><td>
            source=<?= htmlspecialchars((string) ($row['utm_source'] ?? ''), ENT_QUOTES, 'UTF-8') ?><br>
            medium=<?= htmlspecialchars((string) ($row['utm_medium'] ?? ''), ENT_QUOTES, 'UTF-8') ?><br>
            campaign=<?= htmlspecialchars((string) ($row['utm_campaign'] ?? ''), ENT_QUOTES, 'UTF-8') ?><br>
            term=<?= htmlspecialchars((string) ($row['utm_term'] ?? ''), ENT_QUOTES, 'UTF-8') ?><br>
            content=<?= htmlspecialchars((string) ($row['utm_content'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
        </td></tr>
        <tr><td>FBCLID</td><td><?= htmlspecialchars((string) ($row['fbclid'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
        <tr><td>IP / UA</td><td><?= htmlspecialchars((string) ($row['user_ip'] ?? ''), ENT_QUOTES, 'UTF-8') ?><br><small><?= htmlspecialchars((string) ($row['user_agent'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small></td></tr>

        <?php
        $ga4 = (string) ($row['ga4_status'] ?? 'pending');
        $ads = (string) ($row['ads_status'] ?? 'pending');
        $mail = (string) ($row['mail_status'] ?? 'pending');
        $cls = static function (string $s): string {
            return $s === 'success' ? 'ok' : ($s === 'failed' ? 'fail' : 'pending');
        };
        ?>
        <tr><td>GA4 状态</td><td><span class="status <?= $cls($ga4) ?>"><?= htmlspecialchars($ga4, ENT_QUOTES, 'UTF-8') ?></span></td></tr>
        <tr><td>增强转化状态 (Ads)</td><td><span class="status <?= $cls($ads) ?>"><?= htmlspecialchars($ads, ENT_QUOTES, 'UTF-8') ?></span></td></tr>
        <tr><td>邮件状态</td><td><span class="status <?= $cls($mail) ?>"><?= htmlspecialchars($mail, ENT_QUOTES, 'UTF-8') ?></span></td></tr>
        <tr><td>错误信息</td><td><pre><?= htmlspecialchars((string) ($row['error_message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></pre></td></tr>
        <tr><td>询盘时间</td><td><?= htmlspecialchars((string) $row['created_at'], ENT_QUOTES, 'UTF-8') ?></td></tr>
        <tr><td>日志时间</td><td><?= htmlspecialchars((string) ($row['log_created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
    </table>
</div>
<?php admin_ui_end(); ?>
