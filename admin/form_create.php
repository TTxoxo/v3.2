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

$siteStmt = db()->prepare('SELECT s.id, s.site_name,
                           (SELECT COUNT(*) FROM forms f WHERE f.site_id = s.id) AS form_count
                           FROM sites s ORDER BY s.id DESC');
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

        if ($formName === '' || $siteId <= 0) {
            $error = '请填写表单名称并选择所属站点。';
        } else {
            $existsStmt = db()->prepare('SELECT id FROM forms WHERE site_id = :site_id LIMIT 1');
            $existsStmt->execute([':site_id' => $siteId]);
            $exists = $existsStmt->fetch();
            if ($exists) {
                header('Location: /admin/form_edit.php?id=' . (int) $exists['id']);
                exit;
            }

            $stmt = db()->prepare('INSERT INTO forms (site_id, form_name, fields_json, enable_ga4, enable_ads, enable_enhanced_conversion, require_gclid, created_at)
                                   VALUES (:site_id, :form_name, :fields_json, :enable_ga4, :enable_ads, :enable_enhanced_conversion, :require_gclid, NOW())');
            $stmt->execute([
                ':site_id' => $siteId,
                ':form_name' => $formName,
                ':fields_json' => '[]',
                ':enable_ga4' => isset($_POST['enable_ga4']) ? 1 : 0,
                ':enable_ads' => isset($_POST['enable_ads']) ? 1 : 0,
                ':enable_enhanced_conversion' => isset($_POST['enable_enhanced_conversion']) ? 1 : 0,
                ':require_gclid' => isset($_POST['require_gclid']) ? 1 : 0,
            ]);

            $newFormId = (int) db()->lastInsertId();

            $defaults = [];
            foreach (admin_builtin_field_specs() as $k => $meta) {
                $defaults[] = [
                    'key' => $k,
                    'label' => $meta['label'],
                    'type' => $meta['type'],
                    'required' => $meta['required'],
                    'enabled' => true,
                    'placeholder' => '',
                    'options' => '',
                    'display_width' => 'full',
                    'sort_order' => $meta['sort_order'],
                ];
            }
            $legacy = admin_save_form_fields($newFormId, $defaults);
            $up = db()->prepare('UPDATE forms SET fields_json = :fields_json WHERE id = :id');
            $up->execute([':fields_json' => json_encode($legacy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':id' => $newFormId]);

            header('Location: /admin/form_edit.php?id=' . $newFormId);
            exit;
        }
    }
}

admin_ui_start('创建表单', 'forms');
?>
<style>
.container{max-width:860px;margin:0 auto}.field{margin-bottom:12px}.field label{display:block;margin-bottom:6px;color:#374151;font-size:13px}
input,select,button{padding:10px;border:1px solid #d1d5db;border-radius:8px;box-sizing:border-box}input[type="text"],select{width:100%}
.btn{background:#2563eb;color:#fff;border:0;cursor:pointer}.hint{font-size:12px;color:#6b7280}.err{background:#fee2e2;color:#991b1b;padding:10px;border-radius:8px;margin-bottom:12px}
</style>
<div class="container panel">
  <h2 style="margin-top:0">创建表单</h2>
  <?php if ($error !== ''): ?><div class="err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <form method="post" action="">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

    <div class="field">
      <label>表单名称</label>
      <input type="text" name="form_name" required>
    </div>

    <div class="field">
      <label>所属站点（每站点仅一个主表单）</label>
      <select name="site_id" required>
        <option value="">请选择站点</option>
        <?php foreach ($sites as $site): ?>
          <option value="<?= (int) $site['id'] ?>" <?= ((int) $site['form_count'] > 0 ? 'disabled' : '') ?>>
            <?= htmlspecialchars((string) $site['site_name'], ENT_QUOTES, 'UTF-8') ?><?= (int) $site['form_count'] > 0 ? '（已有主表单）' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
      <div class="hint">创建后会自动生成内置字段：name / tel / email / message。自定义字段请在编辑页维护。</div>
    </div>

    <div class="field">
      <label><input type="checkbox" name="enable_ga4" value="1"> enable_ga4</label>
      <label><input type="checkbox" name="enable_ads" value="1"> enable_ads</label>
      <label><input type="checkbox" name="enable_enhanced_conversion" value="1"> enable_enhanced_conversion</label>
      <label><input type="checkbox" name="require_gclid" value="1"> require_gclid</label>
    </div>

    <p>
      <button class="btn" type="submit">创建并进入编辑</button>
      <a class="btn" style="background:#6b7280;text-decoration:none" href="/admin/forms.php">返回列表</a>
    </p>
  </form>
</div>
<?php admin_ui_end(); ?>
