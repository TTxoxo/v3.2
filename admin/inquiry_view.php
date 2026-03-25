<?php
declare(strict_types=1);

session_start();

require __DIR__ . '/../config/database.php';
require __DIR__ . '/_ui.php';
require __DIR__ . '/_fields.php';

if (empty($_SESSION['admin_user_id'])) {
    header('Location: /admin/login.php');
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: /admin/inquiries.php');
    exit;
}

$sql = 'SELECT i.*, s.site_name, s.domain, f.form_name, f.fields_json,
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

$payload = json_decode((string) ($row['payload_json'] ?? ''), true);
if (!is_array($payload)) {
    $payload = [];
}
$labelMap = admin_form_field_label_map((int) $row['form_id'], (string) ($row['fields_json'] ?? '[]'));

admin_ui_start('询盘详情 #' . (int) $row['id'], 'inquiries');
?>
<div class="container">
    <div class="page-head">
        <h2 class="page-title">询盘详情 #<?= (int) $row['id'] ?></h2>
        <a class="btn btn-secondary" href="/admin/inquiries.php">← 返回询盘列表</a>
    </div>
    <div class="panel">
        <h3>基础信息</h3>
        <table class="kv">
            <tr><td>ID</td><td><?= (int) $row['id'] ?></td></tr>
            <tr><td>站点</td><td><?= htmlspecialchars((string) $row['site_name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars((string) $row['domain'], ENT_QUOTES, 'UTF-8') ?>)</td></tr>
            <tr><td>表单</td><td><?= htmlspecialchars((string) $row['form_name'], ENT_QUOTES, 'UTF-8') ?></td></tr>
            <tr><td>姓名</td><td><?= htmlspecialchars((string) ($row['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
            <tr><td>邮箱</td><td><?= htmlspecialchars((string) ($row['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
            <tr><td>电话</td><td><?= htmlspecialchars((string) (($row['tel'] ?? '') !== '' ? $row['tel'] : ($row['phone'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td></tr>
            <tr><td>消息</td><td><pre class="pre-wrap"><?= htmlspecialchars((string) ($row['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></pre></td></tr>
        </table>
    </div>

    <div class="panel">
        <h3>自定义字段</h3>
        <?php if (!$payload): ?>
            <div class="hint">无自定义字段</div>
        <?php else: ?>
            <table class="kv">
                <?php foreach ($payload as $key => $val): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($labelMap[(string) $key] ?? $key), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(is_scalar($val) ? (string) $val : json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>

    <div class="panel">
        <h3>来源与追踪</h3>
        <table class="kv">
            <tr><td>GCLID / WBRAID / GBRAID</td><td><?= htmlspecialchars((string) ($row['gclid'] ?? ''), ENT_QUOTES, 'UTF-8') ?> / <?= htmlspecialchars((string) ($row['wbraid'] ?? ''), ENT_QUOTES, 'UTF-8') ?> / <?= htmlspecialchars((string) ($row['gbraid'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
            <tr><td>Client ID</td><td><?= htmlspecialchars((string) ($row['client_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
            <tr><td>来源渠道</td><td><?= htmlspecialchars((string) ($row['source_channel'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
            <tr><td>来源平台</td><td><?= htmlspecialchars((string) ($row['source_platform'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
            <tr><td>来源媒介</td><td><?= htmlspecialchars((string) ($row['source_medium'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
            <tr><td>Referrer</td><td><pre class="pre-wrap"><?= htmlspecialchars((string) ($row['referrer_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?></pre></td></tr>
            <tr><td>Landing Page</td><td><pre class="pre-wrap"><?= htmlspecialchars((string) ($row['landing_page'] ?? ''), ENT_QUOTES, 'UTF-8') ?></pre></td></tr>
        </table>
    </div>

    <?php
    $ga4 = (string) ($row['ga4_status'] ?? 'pending');
    $ads = (string) ($row['ads_status'] ?? 'pending');
    $mail = (string) ($row['mail_status'] ?? 'pending');
    $cls = static fn(string $s): string => $s === 'success' ? 'badge-ok' : ($s === 'failed' ? 'badge-fail' : 'badge-pending');
    ?>
    <div class="panel">
        <h3>集成与日志状态</h3>
        <table class="kv">
            <tr><td>GA4 状态</td><td><span class="badge <?= $cls($ga4) ?>"><?= htmlspecialchars($ga4, ENT_QUOTES, 'UTF-8') ?></span></td></tr>
            <tr><td>增强转化状态 (Ads)</td><td><span class="badge <?= $cls($ads) ?>"><?= htmlspecialchars($ads, ENT_QUOTES, 'UTF-8') ?></span></td></tr>
            <tr><td>邮件状态</td><td><span class="badge <?= $cls($mail) ?>"><?= htmlspecialchars($mail, ENT_QUOTES, 'UTF-8') ?></span></td></tr>
            <tr><td>错误信息</td><td><pre class="pre-wrap"><?= htmlspecialchars((string) ($row['error_message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></pre></td></tr>
            <tr><td>询盘时间(上海)</td><td><?= htmlspecialchars(admin_format_datetime((string) ($row['created_at'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td></tr>
            <tr><td>日志时间(上海)</td><td><?= htmlspecialchars(admin_format_datetime((string) ($row['log_created_at'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td></tr>
        </table>
    </div>
</div>
<?php admin_ui_end(); ?>
