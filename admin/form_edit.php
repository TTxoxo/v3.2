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

$fields = admin_load_form_fields((int) $form['id'], (string) ($form['fields_json'] ?? '[]'));

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

        $rows = admin_collect_posted_field_rows($_POST);

        if ($formName === '' || $siteId <= 0) {
            $error = '请填写表单名称并选择所属站点。';
        } else {
            $siteFormStmt = db()->prepare('SELECT id FROM forms WHERE site_id = :site_id AND id <> :id LIMIT 1');
            $siteFormStmt->execute([':site_id' => $siteId, ':id' => $formId]);
            $conflictForm = $siteFormStmt->fetch();
            if ($conflictForm) {
                $error = '该站点已存在主表单，请选择其他站点或编辑该主表单。';
            } else {
                try {
                    db()->beginTransaction();

                    $legacyFields = admin_save_form_fields($formId, $rows);

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
                        ':fields_json' => json_encode($legacyFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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

                    db()->commit();

                    header('Location: /admin/forms.php');
                    exit;
                } catch (Throwable $e) {
                    if (db()->inTransaction()) {
                        db()->rollBack();
                    }
                    $error = '保存失败，请检查输入配置后重试。';
                }
            }
        }

        $fields = admin_load_form_fields((int) $form['id'], json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');
    }
}

admin_ui_start('编辑表单', 'forms');
?>
<div class="container">
  <div class="panel">
    <h2 class="panel-title">编辑表单</h2>
    <?php if ($error !== ''): ?><div class="msg err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <form method="post" action="">
      <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

      <div class="form-group">
        <label class="form-label">表单名称</label>
        <input class="form-control" type="text" name="form_name" value="<?= htmlspecialchars((string) $form['form_name'], ENT_QUOTES, 'UTF-8') ?>" required>
      </div>

      <div class="form-group">
        <label class="form-label">所属站点</label>
        <select class="form-control" name="site_id" required>
          <?php foreach ($sites as $site): ?>
            <option value="<?= (int) $site['id'] ?>" <?= ((int) $site['id'] === (int) $form['site_id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars((string) $site['site_name'], ENT_QUOTES, 'UTF-8') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <h3>字段配置（内置字段不可删除）</h3>
      <div class="table-wrap">
      <table class="table table-fields" id="fields-table">
        <thead>
        <tr>
          <th>Key</th><th>标签</th><th>类型</th><th>必填</th><th>启用</th><th>占位符</th><th>选项</th><th>宽度</th><th>排序</th><th>操作</th>
        </tr>
        </thead>
        <tbody id="fields-body">
        <?php foreach ($fields as $i => $f): ?>
          <?php $isBuiltin = !empty($f['is_builtin']); ?>
          <?php $rowId = 'row_' . $i . '_' . substr(md5((string) ($f['key'] ?? $i)), 0, 8); ?>
          <tr class="field-row" data-builtin="<?= $isBuiltin ? '1' : '0' ?>">
            <td>
              <input class="form-control" type="text" name="fields[<?= $rowId ?>][key]" value="<?= htmlspecialchars((string) $f['key'], ENT_QUOTES, 'UTF-8') ?>" <?= $isBuiltin ? 'readonly' : '' ?> required>
              <?php if ($isBuiltin): ?><span class="badge badge-builtin">builtin</span><?php endif; ?>
            </td>
            <td><input class="form-control" type="text" name="fields[<?= $rowId ?>][label]" value="<?= htmlspecialchars((string) $f['label'], ENT_QUOTES, 'UTF-8') ?>" required></td>
            <td>
              <?php $type = (string) ($f['type'] ?? 'text'); ?>
              <select class="form-control" name="fields[<?= $rowId ?>][type]" <?= $isBuiltin ? 'disabled' : '' ?>>
                <?php foreach (['text','email','phone','textarea','select'] as $t): ?>
                  <option value="<?= $t ?>" <?= $type === $t ? 'selected' : '' ?>><?= $t ?></option>
                <?php endforeach; ?>
              </select>
              <?php if ($isBuiltin): ?><input type="hidden" name="fields[<?= $rowId ?>][type]" value="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
            </td>
            <td style="text-align:center"><input type="checkbox" name="fields[<?= $rowId ?>][required]" value="1" <?= !empty($f['required']) ? 'checked' : '' ?>></td>
            <td style="text-align:center">
              <?php if ($isBuiltin): ?><input type="hidden" name="fields[<?= $rowId ?>][enabled]" value="1"><?php endif; ?>
              <input type="checkbox" name="fields[<?= $rowId ?>][enabled]" value="1" <?= !empty($f['enabled']) ? 'checked' : '' ?> <?= $isBuiltin ? 'checked disabled' : '' ?>>
            </td>
            <td><input class="form-control" type="text" name="fields[<?= $rowId ?>][placeholder]" value="<?= htmlspecialchars((string) ($f['placeholder'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
            <td><textarea class="form-control" name="fields[<?= $rowId ?>][options]" rows="2" placeholder="select 类型可填，逗号分隔"><?= htmlspecialchars((string) ($f['options'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea></td>
            <td>
              <?php $w = (string) ($f['display_width'] ?? 'full'); ?>
              <select class="form-control" name="fields[<?= $rowId ?>][display_width]">
                <option value="full" <?= $w === 'full' ? 'selected' : '' ?>>full</option>
                <option value="half" <?= $w === 'half' ? 'selected' : '' ?>>half</option>
              </select>
            </td>
            <td><input class="form-control" type="number" name="fields[<?= $rowId ?>][sort_order]" value="<?= (int) ($f['sort_order'] ?? (($i + 1) * 10)) ?>"></td>
            <td>
              <?php if (!$isBuiltin): ?>
                <button class="btn btn-danger btn-sm" type="button" onclick="removeFieldRow(this)">删除</button>
              <?php else: ?>
                <span class="hint">不可删除</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <p><button class="btn btn-secondary" type="button" onclick="addCustomFieldRow()">+ 添加自定义字段</button></p>

      <div class="form-group">
        <label class="form-label">转化开关</label>
        <div class="checkbox-group">
          <label class="checkbox-item"><input type="checkbox" name="enable_ga4" value="1" <?= (int) $form['enable_ga4'] === 1 ? 'checked' : '' ?>> enable_ga4</label>
          <label class="checkbox-item"><input type="checkbox" name="enable_ads" value="1" <?= (int) $form['enable_ads'] === 1 ? 'checked' : '' ?>> enable_ads</label>
          <label class="checkbox-item"><input type="checkbox" name="enable_enhanced_conversion" value="1" <?= (int) $form['enable_enhanced_conversion'] === 1 ? 'checked' : '' ?>> enable_enhanced_conversion</label>
          <label class="checkbox-item"><input type="checkbox" name="require_gclid" value="1" <?= (int) $form['require_gclid'] === 1 ? 'checked' : '' ?>> require_gclid</label>
        </div>
      </div>

      <h3>站点跟踪配置（GA4 / Ads）</h3>
      <div class="form-grid">
        <div><label class="form-label">GA4 Measurement ID</label><input class="form-control" type="text" name="ga4_measurement_id" value="<?= htmlspecialchars((string) $tracking['ga4_measurement_id'], ENT_QUOTES, 'UTF-8') ?>"></div>
        <div><label class="form-label">GA4 API Secret</label><input class="form-control" type="text" name="ga4_api_secret" value="<?= htmlspecialchars((string) $tracking['ga4_api_secret'], ENT_QUOTES, 'UTF-8') ?>"></div>
        <div><label class="form-label">Ads Conversion ID</label><input class="form-control" type="text" name="ads_conversion_id" value="<?= htmlspecialchars((string) $tracking['ads_conversion_id'], ENT_QUOTES, 'UTF-8') ?>"></div>
        <div><label class="form-label">Ads Conversion Label</label><input class="form-control" type="text" name="ads_conversion_label" value="<?= htmlspecialchars((string) $tracking['ads_conversion_label'], ENT_QUOTES, 'UTF-8') ?>"></div>
        <div class="full"><label class="form-label">该站点收件邮箱（用于邮件通知）</label><input class="form-control" type="text" name="smtp_to_email" value="<?= htmlspecialchars((string) $tracking['smtp_to_email'], ENT_QUOTES, 'UTF-8') ?>"></div>
      </div>

      <div class="actions">
        <button class="btn btn-primary" type="submit">保存修改</button>
        <a class="btn btn-secondary" href="/admin/forms.php">返回</a>
      </div>
    </form>
  </div>
</div>

<script>
function removeFieldRow(btn) {
  var row = btn.closest('tr');
  if (row && row.getAttribute('data-builtin') !== '1') {
    row.remove();
  }
}

function addCustomFieldRow() {
  var tbody = document.getElementById('fields-body');
  var idx = tbody.querySelectorAll('tr').length;
  var rowId = 'row_' + Date.now() + '_' + Math.floor(Math.random() * 100000);
  var tr = document.createElement('tr');
  tr.className = 'field-row';
  tr.setAttribute('data-builtin', '0');
  tr.innerHTML = '' +
    '<td><input class="form-control" type="text" name="fields[' + rowId + '][key]" placeholder="custom_key_' + idx + '" required></td>' +
    '<td><input class="form-control" type="text" name="fields[' + rowId + '][label]" placeholder="字段名称" required></td>' +
    '<td><select class="form-control" name="fields[' + rowId + '][type]"><option value="text">text</option><option value="email">email</option><option value="phone">phone</option><option value="textarea">textarea</option><option value="select">select</option></select></td>' +
    '<td style="text-align:center"><input type="checkbox" name="fields[' + rowId + '][required]" value="1"></td>' +
    '<td style="text-align:center"><input type="checkbox" name="fields[' + rowId + '][enabled]" value="1" checked></td>' +
    '<td><input class="form-control" type="text" name="fields[' + rowId + '][placeholder]"></td>' +
    '<td><textarea class="form-control" name="fields[' + rowId + '][options]" rows="2"></textarea></td>' +
    '<td><select class="form-control" name="fields[' + rowId + '][display_width]"><option value="full">full</option><option value="half">half</option></select></td>' +
    '<td><input class="form-control" type="number" name="fields[' + rowId + '][sort_order]" value="' + ((idx + 1) * 10) + '"></td>' +
    '<td><button class="btn btn-danger btn-sm" type="button" onclick="removeFieldRow(this)">删除</button></td>';
  tbody.appendChild(tr);
}
</script>
<?php admin_ui_end(); ?>
