<?php
declare(strict_types=1);


if (!function_exists('admin_timezone')) {
    function admin_timezone(): DateTimeZone
    {
        static $tz = null;
        if ($tz instanceof DateTimeZone) {
            return $tz;
        }

        $tz = new DateTimeZone('Asia/Shanghai');
        date_default_timezone_set('Asia/Shanghai');
        return $tz;
    }
}

if (!function_exists('admin_format_datetime')) {
    function admin_format_datetime(?string $value, string $format = 'Y-m-d H:i:s'): string
    {
        if ($value === null || trim($value) === '') {
            return '';
        }

        try {
            $dt = new DateTimeImmutable($value);
            return $dt->setTimezone(admin_timezone())->format($format);
        } catch (Throwable $e) {
            return $value;
        }
    }
}

if (!function_exists('admin_now_filename')) {
    function admin_now_filename(string $format = 'Ymd_His'): string
    {
        return (new DateTimeImmutable('now', admin_timezone()))->format($format);
    }
}

if (!function_exists('admin_nav_items')) {
    function admin_nav_items(): array
    {
        return [
            'dashboard' => ['label' => '控制台', 'href' => '/admin/dashboard.php', 'emoji' => '🏠'],
            'sites' => ['label' => '站点管理', 'href' => '/admin/sites.php', 'emoji' => '🌐'],
            'forms' => ['label' => '表单管理', 'href' => '/admin/forms.php', 'emoji' => '🧩'],
            'inquiries' => ['label' => '询盘管理', 'href' => '/admin/inquiries.php', 'emoji' => '📨'],
            'settings' => ['label' => '配置中心', 'href' => '/admin/site_settings.php', 'emoji' => '⚙️'],
        ];
    }
}

if (!function_exists('admin_system_settings')) {
    function admin_system_settings(): array
    {
        static $settings = null;
        if (is_array($settings)) {
            return $settings;
        }

        $settings = [
            'system_name' => '外贸询盘系统',
            'admin_login_name' => '询盘系统后台',
            'admin_login_title' => 'H5 扁平化管理界面',
        ];

        if (function_exists('db')) {
            try {
                $stmt = db()->prepare('SELECT system_name, admin_login_name, admin_login_title FROM system_settings ORDER BY id DESC LIMIT 1');
                $stmt->execute();
                $row = $stmt->fetch();
                if (is_array($row)) {
                    $settings['system_name'] = trim((string) ($row['system_name'] ?? '')) ?: $settings['system_name'];
                    $settings['admin_login_name'] = trim((string) ($row['admin_login_name'] ?? '')) ?: $settings['admin_login_name'];
                    $settings['admin_login_title'] = trim((string) ($row['admin_login_title'] ?? '')) ?: $settings['admin_login_title'];
                }
            } catch (Throwable $e) {
                // ignore missing table in old deployments
            }
        }

        return $settings;
    }
}

if (!function_exists('admin_ui_start')) {
    function admin_ui_start(string $title, string $activeKey = 'dashboard'): void
    {
        $items = admin_nav_items();
        $appSettings = admin_system_settings();
        ?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@latest/dist/css/tabler.min.css">
    <style>
        :root{--page-bg:#f5f7fb;--panel-bg:#fff;--line:#e5e9f2;--muted:#667085;--brand:#206bc4;--nav:#0f172a;--nav-border:#1e293b}
        *{box-sizing:border-box}
        body{margin:0;background:var(--page-bg);color:#111827;font-family:Inter,"PingFang SC","Microsoft YaHei",Arial,sans-serif}
        .layout{min-height:100vh;display:grid;grid-template-columns:240px 1fr}
        .sidebar{background:linear-gradient(180deg,#111827,#0f172a);color:#e2e8f0;padding:16px 12px;border-right:1px solid var(--nav-border)}
        .brand{font-size:18px;font-weight:700;color:#fff;padding:10px 12px 14px;margin-bottom:10px;border-bottom:1px solid #334155;word-break:break-word}
        .nav-list{display:flex;flex-direction:column;gap:6px}
        .nav-item{display:flex;align-items:center;gap:10px;color:#cbd5e1;text-decoration:none;padding:10px 12px;border-radius:10px;font-size:14px;font-weight:500;transition:.2s}
        .nav-item:hover{background:#1e293b;color:#fff}
        .nav-item.active{background:rgba(32,107,196,.18);color:#fff;outline:1px solid rgba(59,130,246,.45)}
        .content{display:flex;flex-direction:column;min-width:0}
        .topbar{height:64px;padding:0 24px;display:flex;justify-content:space-between;align-items:center;background:#fff;border-bottom:1px solid var(--line);position:sticky;top:0;z-index:20}
        .topbar h1{margin:0;font-size:20px;font-weight:700;letter-spacing:.2px}
        .topbar-actions{display:flex;gap:12px;align-items:center}
        .topbar-actions a{color:var(--brand);text-decoration:none;font-size:14px}
        .topbar-actions a:hover{text-decoration:underline}
        .main{padding:18px 24px 28px}
        .container{max-width:1240px;margin:0 auto}

        .panel{background:var(--panel-bg);border:1px solid var(--line);border-radius:12px;padding:16px;box-shadow:0 2px 10px rgba(15,23,42,.03)}
        .msg{padding:10px;border-radius:8px;margin:10px 0;border:1px solid transparent}
        .ok{background:#ecfdf3;color:#166534;border-color:#b7f7d2}
        .err{background:#fff1f2;color:#9f1239;border-color:#fecdd3}

        .btn{display:inline-flex;align-items:center;justify-content:center;padding:8px 12px;border-radius:8px;text-decoration:none;border:0;cursor:pointer;font-size:14px;line-height:1.2}
        .btn-primary{background:#206bc4;color:#fff}
        .btn-success{background:#16a34a;color:#fff}
        .btn-danger{background:#dc2626;color:#fff}

        table{width:100%;border-collapse:collapse;margin-top:12px;background:#fff}
        th,td{border-bottom:1px solid #edf1f7;padding:10px;text-align:left;vertical-align:top}
        th{background:#f8fafc;font-weight:600;color:#344054}
        .pager{margin-top:14px}
        .pager a{margin-right:8px;text-decoration:none;color:#206bc4}

        @media (max-width: 1100px){
            .layout{grid-template-columns:1fr}
            .sidebar{position:static;border-right:0;border-bottom:1px solid var(--nav-border)}
            .topbar{padding:0 14px}
            .main{padding:14px}
        }
    </style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="brand"><?= htmlspecialchars((string) $appSettings['system_name'], ENT_QUOTES, 'UTF-8') ?></div>
        <nav class="nav-list">
            <?php foreach ($items as $key => $item): ?>
                <a href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>" class="nav-item <?= $key === $activeKey ? 'active' : '' ?>">
                    <span><?= htmlspecialchars((string) $item['emoji'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    </aside>

    <section class="content">
        <header class="topbar">
            <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
            <div class="topbar-actions">
                <a href="/admin/dashboard.php">控制台</a>
                <a href="/admin/logout.php">退出</a>
            </div>
        </header>
        <main class="main">
            <div class="container">
<?php
    }
}

if (!function_exists('admin_ui_end')) {
    function admin_ui_end(): void
    {
        ?>
            </div>
        </main>
    </section>
</div>
</body>
</html>
<?php
    }
}
