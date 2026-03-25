<?php
declare(strict_types=1);

if (!function_exists('admin_builtin_field_specs')) {
    function admin_builtin_field_specs(): array
    {
        return [
            'name' => ['label' => 'Name', 'type' => 'text', 'required' => true, 'sort_order' => 10],
            'tel' => ['label' => 'Tel', 'type' => 'phone', 'required' => false, 'sort_order' => 20],
            'email' => ['label' => 'Email', 'type' => 'email', 'required' => true, 'sort_order' => 30],
            'message' => ['label' => 'Message', 'type' => 'textarea', 'required' => false, 'sort_order' => 40],
        ];
    }
}

if (!function_exists('admin_normalize_field_key')) {
    function admin_normalize_field_key(string $raw): string
    {
        $key = strtolower(trim($raw));
        if ($key === 'phone' || $key === 'mobile') {
            $key = 'tel';
        }
        $key = preg_replace('/[^a-z0-9_]/', '_', $key) ?: '';
        $key = preg_replace('/_+/', '_', $key) ?: '';
        return trim($key, '_');
    }
}

if (!function_exists('admin_load_form_fields')) {
    function admin_load_form_fields(int $formId, string $fieldsJson): array
    {
        $fields = [];
        try {
            $stmt = db()->prepare('SELECT field_key, field_label, field_type, is_builtin, is_required, is_active, sort_order, settings_json
                                   FROM form_fields
                                   WHERE form_id = :form_id
                                   ORDER BY sort_order ASC, id ASC');
            $stmt->execute([':form_id' => $formId]);
            $rows = $stmt->fetchAll();
            foreach ($rows as $row) {
                $settings = json_decode((string) ($row['settings_json'] ?? ''), true);
                if (!is_array($settings)) {
                    $settings = [];
                }

                $fields[] = [
                    'key' => (string) $row['field_key'],
                    'label' => (string) $row['field_label'],
                    'type' => (string) $row['field_type'],
                    'required' => (int) $row['is_required'] === 1,
                    'enabled' => (int) $row['is_active'] === 1,
                    'is_builtin' => (int) $row['is_builtin'] === 1,
                    'sort_order' => (int) $row['sort_order'],
                    'placeholder' => trim((string) ($settings['placeholder'] ?? '')),
                    'options' => trim((string) ($settings['options'] ?? '')),
                    'display_width' => trim((string) ($settings['display_width'] ?? 'full')),
                ];
            }
        } catch (Throwable $e) {
            // Compatibility fallback for old deployments before form_fields migration.
            $fields = [];
        }

        if ($fields === []) {
            $legacy = json_decode($fieldsJson, true);
            if (!is_array($legacy)) {
                $legacy = [];
            }

            foreach ($legacy as $i => $field) {
                $key = admin_normalize_field_key((string) ($field['name'] ?? ('field_' . ($i + 1))));
                if ($key === '') {
                    $key = 'custom_' . ($i + 1);
                }
                $fields[] = [
                    'key' => $key,
                    'label' => trim((string) ($field['label'] ?? $key)),
                    'type' => (string) ($field['type'] ?? 'text'),
                    'required' => !empty($field['required']),
                    'enabled' => true,
                    'is_builtin' => in_array($key, ['name', 'tel', 'email', 'message'], true),
                    'sort_order' => (int) ($field['sort'] ?? ($i + 1)),
                    'placeholder' => '',
                    'options' => '',
                    'display_width' => 'full',
                ];
            }
        }

        $specs = admin_builtin_field_specs();
        $indexed = [];
        foreach ($fields as $field) {
            $indexed[(string) $field['key']] = $field;
        }

        foreach ($specs as $key => $meta) {
            if (!isset($indexed[$key])) {
                $indexed[$key] = [
                    'key' => $key,
                    'label' => $meta['label'],
                    'type' => $meta['type'],
                    'required' => $meta['required'],
                    'enabled' => true,
                    'is_builtin' => true,
                    'sort_order' => $meta['sort_order'],
                    'placeholder' => '',
                    'options' => '',
                    'display_width' => 'full',
                ];
            } else {
                $indexed[$key]['is_builtin'] = true;
                $indexed[$key]['key'] = $key;
            }
        }

        $fields = array_values($indexed);
        usort($fields, static fn(array $a, array $b): int => ((int) $a['sort_order']) <=> ((int) $b['sort_order']));

        return $fields;
    }
}

if (!function_exists('admin_save_form_fields')) {
    function admin_save_form_fields(int $formId, array $postedRows): array
    {
        $specs = admin_builtin_field_specs();
        $sanitized = [];

        foreach ($postedRows as $i => $row) {
            $rawKey = (string) ($row['key'] ?? '');
            $key = admin_normalize_field_key($rawKey);
            if ($key === '') {
                continue;
            }

            $isBuiltin = array_key_exists($key, $specs);
            $type = strtolower(trim((string) ($row['type'] ?? 'text')));
            if (!in_array($type, ['text', 'email', 'phone', 'textarea', 'select'], true)) {
                $type = 'text';
            }

            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                $label = $isBuiltin ? $specs[$key]['label'] : $key;
            }

            $required = !empty($row['required']);
            $enabled = !empty($row['enabled']);
            if ($isBuiltin) {
                $enabled = true;
                if (in_array($key, ['name', 'email'], true)) {
                    $required = true;
                }
            }

            $sanitized[$key] = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'required' => $required,
                'enabled' => $enabled,
                'is_builtin' => $isBuiltin,
                'sort_order' => max(1, (int) ($row['sort_order'] ?? (($i + 1) * 10))),
                'placeholder' => trim((string) ($row['placeholder'] ?? '')),
                'options' => trim((string) ($row['options'] ?? '')),
                'display_width' => trim((string) ($row['display_width'] ?? 'full')) ?: 'full',
            ];
        }

        foreach ($specs as $key => $meta) {
            if (!isset($sanitized[$key])) {
                $sanitized[$key] = [
                    'key' => $key,
                    'label' => $meta['label'],
                    'type' => $meta['type'],
                    'required' => $meta['required'],
                    'enabled' => true,
                    'is_builtin' => true,
                    'sort_order' => $meta['sort_order'],
                    'placeholder' => '',
                    'options' => '',
                    'display_width' => 'full',
                ];
            } else {
                $sanitized[$key]['is_builtin'] = true;
                $sanitized[$key]['enabled'] = true;
            }
        }

        $fields = array_values($sanitized);
        usort($fields, static fn(array $a, array $b): int => ((int) $a['sort_order']) <=> ((int) $b['sort_order']));

        try {
            $del = db()->prepare('DELETE FROM form_fields WHERE form_id = :form_id');
            $del->execute([':form_id' => $formId]);

            $ins = db()->prepare('INSERT INTO form_fields
                (form_id, field_key, field_label, field_type, is_builtin, is_required, is_active, sort_order, settings_json, created_at, updated_at)
                VALUES
                (:form_id, :field_key, :field_label, :field_type, :is_builtin, :is_required, :is_active, :sort_order, :settings_json, NOW(), NOW())');

            foreach ($fields as $f) {
                $settingsJson = json_encode([
                    'placeholder' => $f['placeholder'],
                    'options' => $f['options'],
                    'display_width' => $f['display_width'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                $ins->execute([
                    ':form_id' => $formId,
                    ':field_key' => $f['key'],
                    ':field_label' => $f['label'],
                    ':field_type' => $f['type'],
                    ':is_builtin' => $f['is_builtin'] ? 1 : 0,
                    ':is_required' => $f['required'] ? 1 : 0,
                    ':is_active' => $f['enabled'] ? 1 : 0,
                    ':sort_order' => $f['sort_order'],
                    ':settings_json' => $settingsJson,
                ]);
            }
        } catch (Throwable $e) {
            // Compatibility fallback: keep forms.fields_json updated by caller.
        }

        $legacyFields = [];
        foreach ($fields as $f) {
            if (!$f['enabled']) {
                continue;
            }
            $legacyFields[] = [
                'label' => $f['label'],
                'name' => $f['key'],
                'type' => $f['type'],
                'required' => $f['required'],
                'sort' => $f['sort_order'],
            ];
        }

        return $legacyFields;
    }
}

if (!function_exists('admin_form_field_label_map')) {
    function admin_form_field_label_map(int $formId, string $fieldsJson): array
    {
        $fields = admin_load_form_fields($formId, $fieldsJson);
        $map = [];
        foreach ($fields as $f) {
            $map[(string) $f['key']] = (string) $f['label'];
        }
        return $map;
    }
}

if (!function_exists('admin_collect_posted_field_rows')) {
    function admin_collect_posted_field_rows(array $post): array
    {
        if (isset($post['fields']) && is_array($post['fields'])) {
            $rows = [];
            foreach ($post['fields'] as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $requiredRaw = $row['required'] ?? null;
                $enabledRaw = $row['enabled'] ?? null;
                $rows[] = [
                    'key' => (string) ($row['key'] ?? ''),
                    'label' => (string) ($row['label'] ?? ''),
                    'type' => (string) ($row['type'] ?? 'text'),
                    'required' => in_array((string) $requiredRaw, ['1', 'on', 'true'], true),
                    'enabled' => in_array((string) $enabledRaw, ['1', 'on', 'true'], true),
                    'placeholder' => (string) ($row['placeholder'] ?? ''),
                    'options' => (string) ($row['options'] ?? ''),
                    'display_width' => (string) ($row['display_width'] ?? 'full'),
                    'sort_order' => (int) ($row['sort_order'] ?? 10),
                ];
            }

            return $rows;
        }

        $postedKeys = $post['field_key'] ?? [];
        $postedLabels = $post['field_label'] ?? [];
        $postedTypes = $post['field_type'] ?? [];
        $postedRequired = $post['field_required'] ?? [];
        $postedEnabled = $post['field_enabled'] ?? [];
        $postedPlaceholder = $post['field_placeholder'] ?? [];
        $postedOptions = $post['field_options'] ?? [];
        $postedWidth = $post['field_width'] ?? [];
        $postedSort = $post['field_sort'] ?? [];

        $rows = [];
        foreach ($postedKeys as $i => $k) {
            $rows[] = [
                'key' => (string) $k,
                'label' => (string) ($postedLabels[$i] ?? ''),
                'type' => (string) ($postedTypes[$i] ?? 'text'),
                'required' => isset($postedRequired[$i]),
                'enabled' => isset($postedEnabled[$i]),
                'placeholder' => (string) ($postedPlaceholder[$i] ?? ''),
                'options' => (string) ($postedOptions[$i] ?? ''),
                'display_width' => (string) ($postedWidth[$i] ?? 'full'),
                'sort_order' => (int) ($postedSort[$i] ?? (($i + 1) * 10)),
            ];
        }

        return $rows;
    }
}

if (!function_exists('admin_default_field_rows')) {
    function admin_default_field_rows(): array
    {
        $rows = [];
        foreach (admin_builtin_field_specs() as $k => $meta) {
            $rows[] = [
                'key' => $k,
                'label' => $meta['label'],
                'type' => $meta['type'],
                'required' => $meta['required'],
                'enabled' => true,
                'placeholder' => '',
                'options' => '',
                'display_width' => 'full',
                'sort_order' => $meta['sort_order'],
                'is_builtin' => true,
            ];
        }
        return $rows;
    }
}
