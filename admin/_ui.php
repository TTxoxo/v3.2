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
    <link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body class="admin-body">
<div class="layout">
    <aside class="sidebar">
        <div class="brand"><?= htmlspecialchars((string) $appSettings['system_name'], ENT_QUOTES, 'UTF-8') ?></div>
        <nav class="nav-list">
            <?php foreach ($items as $key => $item): ?>
                <a href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>" class="nav-item <?= $key === $activeKey ? 'active' : '' ?>">
                    <span class="nav-emoji"><?= htmlspecialchars((string) $item['emoji'], ENT_QUOTES, 'UTF-8') ?></span>
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
