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

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'delete_inquiry') {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_token'], $csrf)) {
        $_SESSION['flash_error'] = 'CSRF 校验失败，无法删除询盘';
    } else {
        $deleteId = (int) ($_POST['inquiry_id'] ?? 0);
        if ($deleteId > 0) {
            $delStmt = db()->prepare('DELETE FROM inquiries WHERE id = :id LIMIT 1');
            $delStmt->execute([':id' => $deleteId]);
            $_SESSION['flash_message'] = $delStmt->rowCount() > 0 ? ('询盘 #' . $deleteId . ' 已删除') : '未找到可删除的询盘记录';
        } else {
            $_SESSION['flash_error'] = '无效的询盘 ID';
        }
    }

    $redirectQuery = trim((string) ($_POST['redirect_query'] ?? ''));
    $redirect = '/admin/inquiries.php' . ($redirectQuery !== '' ? ('?' . ltrim($redirectQuery, '?')) : '');
    header('Location: ' . $redirect);
    exit;
}

$flashMessage = (string) ($_SESSION['flash_message'] ?? '');
$flashError = (string) ($_SESSION['flash_error'] ?? '');
unset($_SESSION['flash_message'], $_SESSION['flash_error']);

$siteId = (int) ($_GET['site_id'] ?? 0);
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$sourceChannel = trim((string) ($_GET['source_channel'] ?? ''));
$sourceKeyword = trim((string) ($_GET['source_keyword'] ?? ''));
$export = (string) ($_GET['export'] ?? '');

$where = [];
$params = [];

if ($siteId > 0) {
    $where[] = 'i.site_id = :site_id';
    $params[':site_id'] = $siteId;
}
if ($dateFrom !== '') {
    $where[] = 'i.created_at >= :date_from';
    $params[':date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = 'i.created_at <= :date_to';
    $params[':date_to'] = $dateTo . ' 23:59:59';
}
if ($sourceChannel !== '') {
    $where[] = 'i.source_channel = :source_channel';
    $params[':source_channel'] = $sourceChannel;
}
if ($sourceKeyword !== '') {
    $where[] = '(i.source_platform LIKE :source_keyword OR i.utm_source LIKE :source_keyword OR i.utm_campaign LIKE :source_keyword OR i.referrer_url LIKE :source_keyword)';
    $params[':source_keyword'] = '%' . $sourceKeyword . '%';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sitesStmt = db()->prepare('SELECT id, site_name FROM sites ORDER BY id DESC');
$sitesStmt->execute();
$sites = $sitesStmt->fetchAll();

$sourceStmt = db()->prepare("SELECT DISTINCT source_channel FROM inquiries WHERE source_channel IS NOT NULL AND source_channel <> '' ORDER BY source_channel ASC");
$sourceStmt->execute();
$sourceChannels = array_values(array_filter(array_map(static fn($row) => (string) ($row['source_channel'] ?? ''), $sourceStmt->fetchAll())));

$baseSql = 'FROM inquiries i
            INNER JOIN sites s ON s.id = i.site_id
            INNER JOIN forms f ON f.id = i.form_id
            LEFT JOIN form_logs fl ON fl.inquiry_id = i.id
            ' . $whereSql;

if ($export === 'csv') {
    $sql = 'SELECT i.id, s.site_name, f.form_name, i.name, i.email, COALESCE(i.tel, i.phone) AS tel_value,
                   i.payload_json, i.gclid, i.source_channel, i.source_platform, i.utm_source, i.utm_medium, i.referrer_url, i.created_at,
                   fl.ga4_status, fl.ads_status, fl.mail_status, i.form_id, f.fields_json
            ' . $baseSql . '
            ORDER BY i.id DESC';
    $stmt = db()->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inquiries_' . admin_now_filename('Ymd_His') . '.csv"');

    $out = fopen('php://output', 'wb');
    if ($out === false) {
        exit;
    }
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, ['ID', 'Site', 'Form', 'Name', 'Email', 'Tel', 'Custom Fields', 'GCLID', 'Source Channel', 'Source Platform', 'UTM Source', 'UTM Medium', 'Referrer', 'GA4', 'Ads', 'Mail', 'Created At (Asia/Shanghai)']);

    foreach ($rows as $r) {
        $payload = json_decode((string) ($r['payload_json'] ?? ''), true);
        $customReadable = '';
        if (is_array($payload) && $payload) {
            $labelMap = admin_form_field_label_map((int) $r['form_id'], (string) ($r['fields_json'] ?? '[]'));
            $pairs = [];
            foreach ($payload as $key => $val) {
                $label = $labelMap[(string) $key] ?? (string) $key;
                $pairs[] = $label . ': ' . (is_scalar($val) ? (string) $val : json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            $customReadable = implode(' | ', $pairs);
        }

        fputcsv($out, [
            $r['id'],
            $r['site_name'],
            $r['form_name'],
            $r['name'],
            $r['email'],
            $r['tel_value'],
            $customReadable,
            $r['gclid'],
            $r['source_channel'] ?? '',
            $r['source_platform'] ?? '',
            $r['utm_source'] ?? '',
            $r['utm_medium'] ?? '',
            $r['referrer_url'] ?? '',
            $r['ga4_status'] ?? '',
            $r['ads_status'] ?? '',
            $r['mail_status'] ?? '',
            admin_format_datetime((string) $r['created_at']),
        ]);
    }
    fclose($out);
    exit;
}

$countSql = 'SELECT COUNT(*) AS c ' . $baseSql;
$countStmt = db()->prepare($countSql);
foreach ($params as $k => $v) {
    $countStmt->bindValue($k, $v);
}
$countStmt->execute();
$total = (int) ($countStmt->fetch()['c'] ?? 0);

$perPage = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$listSql = 'SELECT i.id, i.name, i.email, COALESCE(i.tel, i.phone) AS tel_value, i.gclid,
                   i.source_channel, i.source_platform, i.utm_source, i.utm_medium, i.created_at,
                   s.site_name, f.form_name,
                   fl.ga4_status, fl.ads_status, fl.mail_status
            ' . $baseSql . '
            ORDER BY i.id DESC
            LIMIT :limit OFFSET :offset';

$listStmt = db()->prepare($listSql);
foreach ($params as $k => $v) {
    $listStmt->bindValue($k, $v);
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$rows = $listStmt->fetchAll();

$queryBase = [
    'site_id' => $siteId > 0 ? (string) $siteId : '',
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'source_channel' => $sourceChannel,
    'source_keyword' => $sourceKeyword,
];

admin_ui_start('询盘管理', 'inquiries');
?>
<div class="container">
    <div class="page-head"><h2 class="page-title">询盘列表</h2></div>
    <?php if ($flashMessage !== ''): ?><div class="msg ok"><?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($flashError !== ''): ?><div class="msg err"><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <form method="get" class="filters">
        <div class="item"><label>站点</label><select class="form-control" name="site_id"><option value="">全部站点</option><?php foreach ($sites as $site): ?><option value="<?= (int) $site['id'] ?>" <?= $siteId === (int) $site['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $site['site_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
        <div class="item"><label>开始日期</label><input class="form-control" type="date" name="date_from" value="<?= htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8') ?>"></div>
        <div class="item"><label>结束日期</label><input class="form-control" type="date" name="date_to" value="<?= htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8') ?>"></div>
        <div class="item"><label>来源渠道</label><select class="form-control" name="source_channel"><option value="">全部渠道</option><?php foreach ($sourceChannels as $ch): ?><option value="<?= htmlspecialchars($ch, ENT_QUOTES, 'UTF-8') ?>" <?= $sourceChannel === $ch ? 'selected' : '' ?>><?= htmlspecialchars($ch, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
        <div class="item"><label>来源关键词</label><input class="form-control" type="text" name="source_keyword" value="<?= htmlspecialchars($sourceKeyword, ENT_QUOTES, 'UTF-8') ?>"></div>
        <div class="item"><button class="btn btn-primary" type="submit">筛选</button></div>
        <div class="item"><?php $csvQuery = $queryBase; $csvQuery['export'] = 'csv'; ?><a class="btn btn-secondary" href="?<?= htmlspecialchars(http_build_query(array_filter($csvQuery, static fn($v) => $v !== '')), ENT_QUOTES, 'UTF-8') ?>">导出 CSV</a></div>
    </form>

    <div class="panel table-wrap">
    <table class="table">
        <thead><tr><th>ID</th><th>站点 / 表单</th><th>姓名/邮箱</th><th>电话</th><th>来源</th><th>增强转化状态</th><th>创建时间(上海)</th><th>操作</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="8">暂无数据</td></tr>
        <?php else: ?>
            <?php foreach ($rows as $r): ?>
                <?php $adsStatus = (string) ($r['ads_status'] ?? 'pending'); $cls = $adsStatus === 'success' ? 'badge-ok' : ($adsStatus === 'failed' ? 'badge-fail' : 'badge-pending'); ?>
                <tr>
                    <td><?= (int) $r['id'] ?></td>
                    <td><?= htmlspecialchars((string) $r['site_name'], ENT_QUOTES, 'UTF-8') ?><br><span class="text-muted"><?= htmlspecialchars((string) $r['form_name'], ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td><?= htmlspecialchars((string) $r['name'], ENT_QUOTES, 'UTF-8') ?><br><span class="text-muted"><?= htmlspecialchars((string) $r['email'], ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td><?= htmlspecialchars((string) ($r['tel_value'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) ($r['source_channel'] ?? 'unknown'), ENT_QUOTES, 'UTF-8') ?><br><span class="text-muted"><?= htmlspecialchars((string) (($r['source_platform'] ?? '') !== '' ? $r['source_platform'] : ($r['utm_source'] ?? '')), ENT_QUOTES, 'UTF-8') ?><?= (($r['utm_medium'] ?? '') !== '' ? ' / ' . htmlspecialchars((string) $r['utm_medium'], ENT_QUOTES, 'UTF-8') : '') ?></span></td>
                    <td><span class="badge <?= $cls ?>"><?= htmlspecialchars($adsStatus, ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td><?= htmlspecialchars(admin_format_datetime((string) $r['created_at']), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><div class="actions"><a class="btn btn-secondary btn-sm" href="/admin/inquiry_view.php?id=<?= (int) $r['id'] ?>">查看</a>
                        <form method="post" onsubmit="return confirm('确认删除该询盘吗？此操作不可恢复。');" style="margin:0;display:inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="delete_inquiry"><input type="hidden" name="inquiry_id" value="<?= (int) $r['id'] ?>">
                            <input type="hidden" name="redirect_query" value="<?= htmlspecialchars(http_build_query(array_filter(array_merge($queryBase, ['page' => (string) $page]), static fn($v) => $v !== '')), ENT_QUOTES, 'UTF-8') ?>">
                            <button type="submit" class="btn btn-danger btn-sm">删除</button>
                        </form></div></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    </div>

    <div class="pager">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <?php $q = $queryBase; $q['page'] = (string) $i; $url = '?' . http_build_query(array_filter($q, static fn($v) => $v !== '')); ?>
            <?php if ($i === $page): ?><strong>[<?= $i ?>]</strong><?php else: ?><a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>">第<?= $i ?>页</a><?php endif; ?>
        <?php endfor; ?>
    </div>
</div>
<?php admin_ui_end(); ?>
