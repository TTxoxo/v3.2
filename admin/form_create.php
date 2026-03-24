<?php
declare(strict_types=1);

session_start();

require __DIR__ . '/../config/database.php';

if (empty($_SESSION['admin_user_id'])) {
    header('Location: /admin/login.php');
    exit;
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$siteStmt = db()->prepare('SELECT id, site_name FROM sites ORDER BY id DESC');
$siteStmt->execute();
$sites = $siteStmt->fetchAll();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_token'], $csrf)) {
        $error = 'CSRF 校验失败。';
    } else {
        $formName = trim((string) ($_POST['form_name'] ?? ''));
        $siteId = (int) ($_POST['site_id'] ?? 0);

        $fieldLabels = $_POST['field_label'] ?? [];
        $fieldTypes = $_POST['field_type'] ?? [];
        $fieldRequired = $_POST['field_required'] ?? [];

        $fields = [];
        foreach ($fieldLabels as $i => $label) {
            $label = trim((string) $label);
            $type = (string) ($fieldTypes[$i] ?? 'text');

            if ($label === '') {
                continue;
            }
            if (!in_array($type, ['text', 'email', 'phone', 'textarea'], true)) {
                continue;
            }

            $fields[] = [
                'label' => $label,
                'name' => 'field_' . ($i + 1),
                'type' => $type,
                'required' => isset($fieldRequired[$i]),
                'sort' => $i + 1,
            ];
        }

        if ($formName === '' || $siteId <= 0) {
            $error = '请填写表单名称并选择所属站点。';
        } elseif (!$fields) {
            $error = '请至少添加一个有效字段。';
        } else {
            $stmt = db()->prepare('INSERT INTO forms (site_id, form_name, fields_json, enable_ga4, enable_ads, enable_enhanced_conversion, require_gclid, created_at)
                                   VALUES (:site_id, :form_name, :fields_json, :enable_ga4, :enable_ads, :enable_enhanced_conversion, :require_gclid, NOW())');
            $stmt->execute([
                ':site_id' => $siteId,
                ':form_name' => $formName,
                ':fields_json' => json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':enable_ga4' => isset($_POST['enable_ga4']) ? 1 : 0,
                ':enable_ads' => isset($_POST['enable_ads']) ? 1 : 0,
                ':enable_enhanced_conversion' => isset($_POST['enable_enhanced_conversion']) ? 1 : 0,
                ':require_gclid' => isset($_POST['require_gclid']) ? 1 : 0,
            ]);

            header('Location: /admin/forms.php');
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>创建表单</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f3f4f6;margin:0}
        .container{max-width:900px;margin:30px auto;background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,.06)}
        input,select,button{padding:10px;border:1px solid #d1d5db;border-radius:6px;box-sizing:border-box}
        input[type="text"],select{width:100%}
        .row{display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:8px;align-items:center;margin-bottom:8px}
        .btn{background:#2563eb;color:#fff;border:0;cursor:pointer}
        .btn-secondary{background:#6b7280;color:#fff;text-decoration:none;padding:10px 12px;border-radius:6px;display:inline-block}
        .err{background:#fee2e2;color:#991b1b;padding:10px;border-radius:6px;margin-bottom:12px}
        .opt{margin-top:12px}
        .opt label{margin-right:12px}
    </style>
</head>
<body>
<div class="container">
    <h2>创建表单</h2>

    <?php if ($error !== ''): ?><div class="err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <form method="post" action="">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

        <p>
            <label>表单名称</label><br>
            <input type="text" name="form_name" required>
        </p>

        <p>
            <label>所属站点</label><br>
            <select name="site_id" required>
                <option value="">请选择站点</option>
                <?php foreach ($sites as $site): ?>
                    <option value="<?= (int) $site['id'] ?>"><?= htmlspecialchars((string) $site['site_name'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </p>

        <h3>字段配置（支持排序）</h3>
        <p style="font-size:12px;color:#6b7280">使用“上移/下移”按钮调整顺序，提交后按当前顺序写入 JSON。</p>
        <div id="fields-wrap">
            <div class="row field-item">
                <input type="text" name="field_label[]" placeholder="字段名，如 Name" required>
                <select name="field_type[]">
                    <option value="text">text</option>
                    <option value="email">email</option>
                    <option value="phone">phone</option>
                    <option value="textarea">textarea</option>
                </select>
                <label><input type="checkbox" name="field_required[0]" value="1"> 必填</label>
                <div>
                    <button type="button" onclick="moveUp(this)">上移</button>
                    <button type="button" onclick="moveDown(this)">下移</button>
                    <button type="button" onclick="removeField(this)">删除</button>
                </div>
            </div>
        </div>
        <p><button class="btn" type="button" onclick="addField()">+ 添加字段</button></p>

        <div class="opt">
            <label><input type="checkbox" name="enable_ga4" value="1"> enable_ga4</label>
            <label><input type="checkbox" name="enable_ads" value="1"> enable_ads</label>
            <label><input type="checkbox" name="enable_enhanced_conversion" value="1"> enable_enhanced_conversion</label>
            <label><input type="checkbox" name="require_gclid" value="1"> require_gclid</label>
        </div>

        <p>
            <button class="btn" type="submit">创建表单</button>
            <a class="btn-secondary" href="/admin/forms.php">返回列表</a>
        </p>
    </form>
</div>
<script>
let idx = 1;

function addField() {
    const wrap = document.getElementById('fields-wrap');
    const row = document.createElement('div');
    row.className = 'row field-item';
    row.innerHTML = `
        <input type="text" name="field_label[]" placeholder="字段名" required>
        <select name="field_type[]">
            <option value="text">text</option>
            <option value="email">email</option>
            <option value="phone">phone</option>
            <option value="textarea">textarea</option>
        </select>
        <label><input type="checkbox" name="field_required[${idx}]" value="1"> 必填</label>
        <div>
            <button type="button" onclick="moveUp(this)">上移</button>
            <button type="button" onclick="moveDown(this)">下移</button>
            <button type="button" onclick="removeField(this)">删除</button>
        </div>
    `;
    wrap.appendChild(row);
    idx++;
}

function removeField(btn) {
    const items = document.querySelectorAll('.field-item');
    if (items.length <= 1) {
        alert('至少保留一个字段');
        return;
    }
    btn.closest('.field-item').remove();
}

function moveUp(btn) {
    const row = btn.closest('.field-item');
    const prev = row.previousElementSibling;
    if (prev) {
        row.parentNode.insertBefore(row, prev);
    }
}

function moveDown(btn) {
    const row = btn.closest('.field-item');
    const next = row.nextElementSibling;
    if (next) {
        row.parentNode.insertBefore(next, row);
    }
}
</script>
</body>
</html>
