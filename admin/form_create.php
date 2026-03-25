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
$formNameInput = '';
$siteIdInput = 0;
$trackingInput = [
    'ga4_measurement_id' => '',
    'ga4_api_secret' => '',
    'ads_conversion_id' => '',
    'ads_conversion_label' => '',
    'smtp_to_email' => '',
];
$flagsInput = [
    'enable_ga4' => false,
    'enable_ads' => false,
    'enable_enhanced_conversion' => false,
    'require_gclid' => false,
];
$fields = admin_default_field_rows();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_token'], $csrf)) {
        $error = 'CSRF 校验失败。';
    } else {
        $formNameInput = trim((string) ($_POST['form_name'] ?? ''));
        $siteIdInput = (int) ($_POST['site_id'] ?? 0);
        $trackingInput = [
            'ga4_measurement_id' => trim((string) ($_POST['ga4_measurement_id'] ?? '')),
            'ga4_api_secret' => trim((string) ($_POST['ga4_api_secret'] ?? '')),
            'ads_conversion_id' => trim((string) ($_POST['ads_conversion_id'] ?? '')),
            'ads_conversion_label' => trim((string) ($_POST['ads_conversion_label'] ?? '')),
            'smtp_to_email' => trim((string) ($_POST['smtp_to_email'] ?? '')),
        ];
        $flagsInput = [
            'enable_ga4' => isset($_POST['enable_ga4']),
            'enable_ads' => isset($_POST['enable_ads']),
            'enable_enhanced_conversion' => isset($_POST['enable_enhanced_conversion']),
            'require_gclid' => isset($_POST['require_gclid']),
        ];

        $postedRows = admin_collect_posted_field_rows($_POST);
        if ($postedRows !== []) {
            $fields = $postedRows;
        }

        if ($formNameInput === '' || $siteIdInput <= 0) {
            $error = '请填写表单名称并选择所属站点。';
        } else {
            $existsStmt = db()->prepare('SELECT id FROM forms WHERE site_id = :site_id LIMIT 1');
            $existsStmt->execute([':site_id' => $siteIdInput]);
            $exists = $existsStmt->fetch();
            if ($exists) {
                $error = '该站点已存在主表单，请直接进入编辑。';
            } else {
                try {
                    db()->beginTransaction();

                    $stmt = db()->prepare('INSERT INTO forms (site_id, form_name, fields_json, enable_ga4, enable_ads, enable_enhanced_conversion, require_gclid, created_at)
                                           VALUES (:site_id, :form_name, :fields_json, :enable_ga4, :enable_ads, :enable_enhanced_conversion, :require_gclid, NOW())');
                    $stmt->execute([
                        ':site_id' => $siteIdInput,
                        ':form_name' => $formNameInput,
                        ':fields_json' => '[]',
                        ':enable_ga4' => $flagsInput['enable_ga4'] ? 1 : 0,
                        ':enable_ads' => $flagsInput['enable_ads'] ? 1 : 0,
                        ':enable_enhanced_conversion' => $flagsInput['enable_enhanced_conversion'] ? 1 : 0,
                        ':require_gclid' => $flagsInput['require_gclid'] ? 1 : 0,
                    ]);

                    $newFormId = (int) db()->lastInsertId();

                    $legacy = admin_save_form_fields($newFormId, $fields);
                    $up = db()->prepare('UPDATE forms SET fields_json = :fields_json WHERE id = :id');
                    $up->execute([
                        ':fields_json' => json_encode($legacy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ':id' => $newFormId,
                    ]);

                    $trackingData = [
                        ':site_id' => $siteIdInput,
                        ':ga4_measurement_id' => $trackingInput['ga4_measurement_id'],
                        ':ga4_api_secret' => $trackingInput['ga4_api_secret'],
                        ':ads_conversion_id' => $trackingInput['ads_conversion_id'],
                        ':ads_conversion_label' => $trackingInput['ads_conversion_label'],
                        ':smtp_to_email' => $trackingInput['smtp_to_email'],
                    ];

                    $existsSettingStmt = db()->prepare('SELECT id FROM site_settings WHERE site_id = :site_id LIMIT 1');
                    $existsSettingStmt->execute([':site_id' => $siteIdInput]);
                    $existsSetting = $existsSettingStmt->fetch();

                    if ($existsSetting) {
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

                    header('Location: /admin/form_edit.php?id=' . $newFormId);
                    exit;
                } catch (Throwable $e) {
                    if (db()->inTransaction()) {
                        db()->rollBack();
                    }
                    $error = '创建失败，请检查站点是否已存在主表单或配置是否有效。';
                }
            }
        }
    }
}

admin_ui_start('创建表单', 'forms');
?>
<style>
.wrap{max-width:1180px;margin:0 auto}.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px}
.field{margin-bottom:12px}.field label{font-size:13px;color:#374151;display:block;margin-bottom:6px}
input,select,button,textarea{padding:8px;border:1px solid #d1d5db;border-radius:8px;box-sizing:border-box;font-size:13px}
input[type="text"],select,textarea{width:100%}.btn{background:#2563eb;color:#fff;border:0;cursor:pointer}
.btn-secondary{background:#6b7280;color:#fff;text-decoration:none;padding:10px 12px;border-radius:8px;display:inline-block}
.err{background:#fee2e2;color:#991b1b;padding:10px;border-radius:8px;margin-bottom:12px}
.actions{margin-top:12px;display:flex;gap:10px;align-items:center}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.full{grid-column:1 / 3}
.table-fields{width:100%;border-collapse:collapse;margin-top:8px}.table-fields th,.table-fields td{border:1px solid #e5e7eb;padding:8px;vertical-align:top}
.badge{display:inline-block;padding:2px 6px;border-radius:999px;font-size:12px}.badge-builtin{background:#e0e7ff;color:#3730a3}.hint{font-size:12px;color:#6b7280}
@media (max-width:980px){.grid{grid-template-columns:1fr}.full{grid-column:auto}}
</style>
<div class="wrap">
  <div class="card">
    <h2 style="margin-top:0">创建表单</h2>
    <?php if ($error !== ''): ?><div class="err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <form method="post" action="">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

      <div class="field">
        <label>表单名称</label>
        <input type="text" name="form_name" value="<?= htmlspecialchars($formNameInput, ENT_QUOTES, 'UTF-8') ?>" required>
      </div>

      <div class="field">
        <label>所属站点（每站点仅一个主表单）</label>
        <select name="site_id" required>
          <option value="">请选择站点</option>
          <?php foreach ($sites as $site): ?>
            <?php
            $id = (int) $site['id'];
            $disabled = ((int) $site['form_count'] > 0 && $id !== $siteIdInput);
            ?>
            <option value="<?= $id ?>" <?= $siteIdInput === $id ? 'selected' : '' ?> <?= $disabled ? 'disabled' : '' ?>>
              <?= htmlspecialchars((string) $site['site_name'], ENT_QUOTES, 'UTF-8') ?><?= (int) $site['form_count'] > 0 ? '（已有主表单）' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <h3>字段配置（内置字段默认展示且不可删除）</h3>
      <div class="hint">内置字段：name / tel / email / message。可直接在此页新增自定义字段并设置属性。</div>
      <table class="table-fields" id="fields-table">
        <thead>
        <tr>
          <th>Key</th><th>标签</th><th>类型</th><th>必填</th><th>启用</th><th>占位符</th><th>选项</th><th>宽度</th><th>排序</th><th>操作</th>
        </tr>
        </thead>
        <tbody id="fields-body">
        <?php foreach ($fields as $i => $f): ?>
          <?php
          $key = admin_normalize_field_key((string) ($f['key'] ?? ''));
          $isBuiltin = !empty($f['is_builtin']) || in_array($key, ['name', 'tel', 'email', 'message'], true);
          $type = (string) ($f['type'] ?? 'text');
          $rowId = 'row_' . $i . '_' . substr(md5((string) ($key ?: $i)), 0, 8);
          ?>
          <tr class="field-row" data-builtin="<?= $isBuiltin ? '1' : '0' ?>">
            <td>
              <input type="text" name="fields[<?= $rowId ?>][key]" value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" <?= $isBuiltin ? 'readonly' : '' ?> required>
              <?php if ($isBuiltin): ?><span class="badge badge-builtin">builtin</span><?php endif; ?>
            </td>
            <td><input type="text" name="fields[<?= $rowId ?>][label]" value="<?= htmlspecialchars((string) ($f['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required></td>
            <td>
              <select name="fields[<?= $rowId ?>][type]" <?= $isBuiltin ? 'disabled' : '' ?>>
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
            <td><input type="text" name="fields[<?= $rowId ?>][placeholder]" value="<?= htmlspecialchars((string) ($f['placeholder'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></td>
            <td><textarea name="fields[<?= $rowId ?>][options]" rows="2" placeholder="select 类型可填，逗号分隔"><?= htmlspecialchars((string) ($f['options'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea></td>
            <td>
              <?php $w = (string) ($f['display_width'] ?? 'full'); ?>
              <select name="fields[<?= $rowId ?>][display_width]">
                <option value="full" <?= $w === 'full' ? 'selected' : '' ?>>full</option>
                <option value="half" <?= $w === 'half' ? 'selected' : '' ?>>half</option>
              </select>
            </td>
            <td><input type="number" name="fields[<?= $rowId ?>][sort_order]" value="<?= (int) ($f['sort_order'] ?? (($i + 1) * 10)) ?>"></td>
            <td>
              <?php if (!$isBuiltin): ?>
                <button type="button" onclick="removeFieldRow(this)">删除</button>
              <?php else: ?>
                <span style="color:#6b7280">不可删除</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p><button class="btn" type="button" onclick="addCustomFieldRow()">+ 添加自定义字段</button></p>

      <div class="field">
        <label>转化开关</label>
        <label><input type="checkbox" name="enable_ga4" value="1" <?= $flagsInput['enable_ga4'] ? 'checked' : '' ?>> enable_ga4</label>
        <label><input type="checkbox" name="enable_ads" value="1" <?= $flagsInput['enable_ads'] ? 'checked' : '' ?>> enable_ads</label>
        <label><input type="checkbox" name="enable_enhanced_conversion" value="1" <?= $flagsInput['enable_enhanced_conversion'] ? 'checked' : '' ?>> enable_enhanced_conversion</label>
        <label><input type="checkbox" name="require_gclid" value="1" <?= $flagsInput['require_gclid'] ? 'checked' : '' ?>> require_gclid</label>
      </div>

      <h3 style="margin-top:16px">站点跟踪配置（GA4 / Ads）</h3>
      <div class="grid">
        <div><label>GA4 Measurement ID</label><input type="text" name="ga4_measurement_id" value="<?= htmlspecialchars((string) $trackingInput['ga4_measurement_id'], ENT_QUOTES, 'UTF-8') ?>"></div>
        <div><label>GA4 API Secret</label><input type="text" name="ga4_api_secret" value="<?= htmlspecialchars((string) $trackingInput['ga4_api_secret'], ENT_QUOTES, 'UTF-8') ?>"></div>
        <div><label>Ads Conversion ID</label><input type="text" name="ads_conversion_id" value="<?= htmlspecialchars((string) $trackingInput['ads_conversion_id'], ENT_QUOTES, 'UTF-8') ?>"></div>
        <div><label>Ads Conversion Label</label><input type="text" name="ads_conversion_label" value="<?= htmlspecialchars((string) $trackingInput['ads_conversion_label'], ENT_QUOTES, 'UTF-8') ?>"></div>
        <div class="full"><label>该站点收件邮箱（用于邮件通知）</label><input type="text" name="smtp_to_email" value="<?= htmlspecialchars((string) $trackingInput['smtp_to_email'], ENT_QUOTES, 'UTF-8') ?>"></div>
      </div>

      <div class="actions">
        <button class="btn" type="submit">创建并进入编辑</button>
        <a class="btn-secondary" href="/admin/forms.php">返回</a>
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
    '<td><input type="text" name="fields[' + rowId + '][key]" placeholder="custom_key_' + idx + '" required></td>' +
    '<td><input type="text" name="fields[' + rowId + '][label]" placeholder="字段名称" required></td>' +
    '<td><select name="fields[' + rowId + '][type]"><option value="text">text</option><option value="email">email</option><option value="phone">phone</option><option value="textarea">textarea</option><option value="select">select</option></select></td>' +
    '<td style="text-align:center"><input type="checkbox" name="fields[' + rowId + '][required]" value="1"></td>' +
    '<td style="text-align:center"><input type="checkbox" name="fields[' + rowId + '][enabled]" value="1" checked></td>' +
    '<td><input type="text" name="fields[' + rowId + '][placeholder]"></td>' +
    '<td><textarea name="fields[' + rowId + '][options]" rows="2"></textarea></td>' +
    '<td><select name="fields[' + rowId + '][display_width]"><option value="full">full</option><option value="half">half</option></select></td>' +
    '<td><input type="number" name="fields[' + rowId + '][sort_order]" value="' + ((idx + 1) * 10) + '"></td>' +
    '<td><button type="button" onclick="removeFieldRow(this)">删除</button></td>';
  tbody.appendChild(tr);
}
</script>
<?php admin_ui_end(); ?>
