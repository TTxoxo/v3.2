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

$formId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($formId <= 0) {
    header('Location: /admin/forms.php');
    exit;
}

$siteStmt = db()->prepare('SELECT id, site_name FROM sites ORDER BY id DESC');
$siteStmt->execute();
$sites = $siteStmt->fetchAll();

$stmt = db()->prepare('SELECT * FROM forms WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $formId]);
$form = $stmt->fetch();

if (!$form) {
    header('Location: /admin/forms.php');
    exit;
}

$fields = json_decode((string) $form['fields_json'], true);
if (!is_array($fields)) {
    $fields = [];
}

$tracking = [
    'ga4_measurement_id' => '',
    'ga4_api_secret' => '',
    'ads_conversion_id' => '',
    'ads_conversion_label' => '',
    'smtp_to_email' => '',
];

$trackingStmt = db()->prepare('SELECT ga4_measurement_id, ga4_api_secret, ads_conversion_id, ads_conversion_label, smtp_to_email FROM site_settings WHERE site_id = :site_id LIMIT 1');
$trackingStmt->execute([':site_id' => (int) $form['site_id']]);
$trackingRow = $trackingStmt->fetch();
if ($trackingRow) {
    $tracking = array_merge($tracking, $trackingRow);
}

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

        $updatedFields = [];
        foreach ($fieldLabels as $i => $label) {
            $label = trim((string) $label);
            $type = (string) ($fieldTypes[$i] ?? 'text');

            if ($label === '') {
                continue;
            }
            if (!in_array($type, ['text', 'email', 'phone', 'textarea'], true)) {
                continue;
            }

            $updatedFields[] = [
                'label' => $label,
                'name' => 'field_' . ($i + 1),
                'type' => $type,
                'required' => isset($fieldRequired[$i]),
                'sort' => $i + 1,
            ];
        }

        if ($formName === '' || $siteId <= 0) {
            $error = '请填写表单名称并选择所属站点。';
        } elseif (!$updatedFields) {
            $error = '请至少保留一个有效字段。';
        } else {
            $up = db()->prepare('UPDATE forms
                                 SET site_id = :site_id,
                                     form_name = :form_name,
                                     fields_json = :fields_json,
                                     enable_ga4 = :enable_ga4,
                                     enable_ads = :enable_ads,
                                     enable_enhanced_conversion = :enable_enhanced_conversion,
                                     require_gclid = :require_gclid
                                 WHERE id = :id');
            $up->execute([
                ':site_id' => $siteId,
                ':form_name' => $formName,
                ':fields_json' => json_encode($updatedFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':enable_ga4' => isset($_POST['enable_ga4']) ? 1 : 0,
                ':enable_ads' => isset($_POST['enable_ads']) ? 1 : 0,
                ':enable_enhanced_conversion' => isset($_POST['enable_enhanced_conversion']) ? 1 : 0,
                ':require_gclid' => isset($_POST['require_gclid']) ? 1 : 0,
                ':id' => $formId,
            ]);

            $trackingData = [
                ':site_id' => $siteId,
                ':ga4_measurement_id' => trim((string) ($_POST['ga4_measurement_id'] ?? '')),
                ':ga4_api_secret' => trim((string) ($_POST['ga4_api_secret'] ?? '')),
                ':ads_conversion_id' => trim((string) ($_POST['ads_conversion_id'] ?? '')),
                ':ads_conversion_label' => trim((string) ($_POST['ads_conversion_label'] ?? '')),
                ':smtp_to_email' => trim((string) ($_POST['smtp_to_email'] ?? '')),
            ];

            $existsStmt = db()->prepare('SELECT id FROM site_settings WHERE site_id = :site_id LIMIT 1');
            $existsStmt->execute([':site_id' => $siteId]);
            $exists = $existsStmt->fetch();

            if ($exists) {
                $saveTrackingSql = 'UPDATE site_settings SET
                                        ga4_measurement_id = :ga4_measurement_id,
                                        ga4_api_secret = :ga4_api_secret,
                                        ads_conversion_id = :ads_conversion_id,
                                        ads_conversion_label = :ads_conversion_label,
                                        smtp_to_email = :smtp_to_email
                                    WHERE site_id = :site_id';
            } else {
                $saveTrackingSql = 'INSERT INTO site_settings
                                    (site_id, ga4_measurement_id, ga4_api_secret, ads_conversion_id, ads_conversion_label, smtp_to_email, created_at)
                                    VALUES
                                    (:site_id, :ga4_measurement_id, :ga4_api_secret, :ads_conversion_id, :ads_conversion_label, :smtp_to_email, NOW())';
            }
            $saveTrackingStmt = db()->prepare($saveTrackingSql);
            $saveTrackingStmt->execute($trackingData);

            header('Location: /admin/forms.php');
            exit;
        }

        $tracking['ga4_measurement_id'] = trim((string) ($_POST['ga4_measurement_id'] ?? ''));
        $tracking['ga4_api_secret'] = trim((string) ($_POST['ga4_api_secret'] ?? ''));
        $tracking['ads_conversion_id'] = trim((string) ($_POST['ads_conversion_id'] ?? ''));
        $tracking['ads_conversion_label'] = trim((string) ($_POST['ads_conversion_label'] ?? ''));
        $tracking['smtp_to_email'] = trim((string) ($_POST['smtp_to_email'] ?? ''));
    }
}
?>

<?php admin_ui_start('编辑表单', 'forms'); ?>
<style>
.wrap{max-width:980px;margin:0 auto}.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px}.row{display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:8px;align-items:center;margin-bottom:8px}.field{margin-bottom:12px}.field label{font-size:13px;color:#374151;display:block;margin-bottom:6px}input,select,button{padding:10px;border:1px solid #d1d5db;border-radius:8px;box-sizing:border-box}input[type="text"],select{width:100%}.btn{background:#2563eb;color:#fff;border:0;cursor:pointer}.btn-secondary{background:#6b7280;color:#fff;text-decoration:none;padding:10px 12px;border-radius:8px;display:inline-block}.err{background:#fee2e2;color:#991b1b;padding:10px;border-radius:8px;margin-bottom:12px}.opt{margin-top:12px}.opt label{margin-right:12px}.actions{margin-top:12px;display:flex;gap:10px;align-items:center}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.full{grid-column:1 / 3}@media (max-width:900px){.grid{grid-template-columns:1fr}.full{grid-column:auto}}
</style>
<div class="wrap">
  <div class="card">
    <h2 style="margin-top:0">编辑表单</h2>
    <?php if ($error !== ''): ?><div class="err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <form method="post" action="">
      <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

      <div class="field">
        <label>表单名称</label>
        <input type="text" name="form_name" value="<?= htmlspecialchars((string) $form['form_name'], ENT_QUOTES, 'UTF-8') ?>" required>
      </div>

      <div class="field">
        <label>所属站点</label>
        <select name="site_id" required>
          <?php foreach ($sites as $site): ?>
            <option value="<?= (int) $site['id'] ?>" <?= ((int) $site['id'] === (int) $form['site_id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars((string) $site['site_name'], ENT_QUOTES, 'UTF-8') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <h3>字段配置（支持排序）</h3>
      <div id="fields-wrap">
        <?php if (!$fields): ?>
          <div class="row field-item">
            <input type="text" name="field_label[]" placeholder="字段名称（如 Name）" required>
            <select name="field_type[]">
              <option value="text">text</option>
              <option value="email">email</option>
              <option value="phone">phone</option>
              <option value="textarea">textarea</option>
            </select>
            <label><input type="checkbox" name="field_required[0]" value="1">必填</label>
            <button type="button" onclick="removeField(this)">删除</button>
          </div>
        <?php else: ?>
          <?php foreach ($fields as $i => $field): ?>
            <div class="row field-item">
              <input type="text" name="field_label[]" value="<?= htmlspecialchars((string) ($field['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
              <select name="field_type[]">
                <?php $type = (string) ($field['type'] ?? 'text'); ?>
                <option value="text" <?= $type === 'text' ? 'selected' : '' ?>>text</option>
                <option value="email" <?= $type === 'email' ? 'selected' : '' ?>>email</option>
                <option value="phone" <?= $type === 'phone' ? 'selected' : '' ?>>phone</option>
                <option value="textarea" <?= $type === 'textarea' ? 'selected' : '' ?>>textarea</option>
              </select>
              <label><input type="checkbox" name="field_required[<?= (int) $i ?>]" value="1" <?= !empty($field['required']) ? 'checked' : '' ?>>必填</label>
              <button type="button" onclick="removeField(this)">删除</button>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <p><button class="btn" type="button" onclick="addField()">+ 添加字段</button></p>

      <div class="opt">
        <label><input type="checkbox" name="enable_ga4" value="1" <?= (int) $form['enable_ga4'] === 1 ? 'checked' : '' ?>> enable_ga4</label>
        <label><input type="checkbox" name="enable_ads" value="1" <?= (int) $form['enable_ads'] === 1 ? 'checked' : '' ?>> enable_ads</label>
        <label><input type="checkbox" name="enable_enhanced_conversion" value="1" <?= (int) $form['enable_enhanced_conversion'] === 1 ? 'checked' : '' ?>> enable_enhanced_conversion</label>
        <label><input type="checkbox" name="require_gclid" value="1" <?= (int) $form['require_gclid'] === 1 ? 'checked' : '' ?>> require_gclid</label>
      </div>

      <h3 style="margin-top:16px">站点跟踪配置（GA4 / Ads）</h3>
      <div class="grid">
        <div><label>GA4 Measurement ID</label><input type="text" name="ga4_measurement_id" value="<?= htmlspecialchars((string) $tracking['ga4_measurement_id'], ENT_QUOTES, 'UTF-8') ?>"></div>
        <div><label>GA4 API Secret</label><input type="text" name="ga4_api_secret" value="<?= htmlspecialchars((string) $tracking['ga4_api_secret'], ENT_QUOTES, 'UTF-8') ?>"></div>
        <div><label>Ads Conversion ID</label><input type="text" name="ads_conversion_id" value="<?= htmlspecialchars((string) $tracking['ads_conversion_id'], ENT_QUOTES, 'UTF-8') ?>"></div>
        <div><label>Ads Conversion Label</label><input type="text" name="ads_conversion_label" value="<?= htmlspecialchars((string) $tracking['ads_conversion_label'], ENT_QUOTES, 'UTF-8') ?>"></div>
        <div class="full"><label>该站点收件邮箱（用于邮件通知）</label><input type="text" name="smtp_to_email" placeholder="a@company.com, b@company.com 或换行分隔" value="<?= htmlspecialchars((string) $tracking['smtp_to_email'], ENT_QUOTES, 'UTF-8') ?>"></div>
      </div>

      <div class="actions">
        <button class="btn" type="submit">保存修改</button>
        <a class="btn-secondary" href="/admin/forms.php">返回</a>
      </div>
    </form>
  </div>
</div>

<script>
function addField() {
  var wrap = document.getElementById('fields-wrap');
  var index = wrap.querySelectorAll('.field-item').length;
  var row = document.createElement('div');
  row.className = 'row field-item';
  row.innerHTML = '' +
    '<input type="text" name="field_label[]" placeholder="字段名称" required>' +
    '<select name="field_type[]">' +
      '<option value="text">text</option>' +
      '<option value="email">email</option>' +
      '<option value="phone">phone</option>' +
      '<option value="textarea">textarea</option>' +
    '</select>' +
    '<label><input type="checkbox" name="field_required[' + index + ']" value="1">必填</label>' +
    '<button type="button" onclick="removeField(this)">删除</button>';
  wrap.appendChild(row);
}

function removeField(btn) {
  var row = btn.closest('.field-item');
  if (!row) return;
  row.remove();
}
</script>
<?php admin_ui_end(); ?>
