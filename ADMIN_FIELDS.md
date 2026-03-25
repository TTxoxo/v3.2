# ADMIN_FIELDS.md

## Field model
The admin now treats form fields with **explicit separation**:

- **Builtin system fields** (non-deletable):
  - `name`
  - `tel`
  - `email`
  - `message`
- **Custom fields** (admin-manageable): any non-builtin `field_key`.

`form_fields` is the primary source of truth. `forms.fields_json` is still synchronized for backward compatibility.

## Builtin constraints
Builtin fields are protected by admin logic:
- Key is fixed and cannot be converted.
- Row cannot be deleted.
- Always present even if legacy data is incomplete.
- Required flags for `name` and `email` are enforced as system-required.

## Configurable field properties
Both builtin and custom fields can store/administer:
- `field_label`
- `field_type`
- `is_required`
- `is_enabled` (builtin remains enabled)
- `placeholder` (stored in `settings_json`)
- `options` (stored in `settings_json`, useful for `select`)
- `sort_order`
- `display_width` (stored in `settings_json`)

## Inquiry display data source
Admin inquiry pages now display:
- Builtin fields from dedicated inquiry columns (`name`, `tel`/`phone`, `email`, `message`).
- Custom fields from `inquiries.payload_json`.
- Custom field labels resolved from form field definitions (`form_fields` fallback to legacy `fields_json`).

## Backward compatibility
- Reads prefer `form_fields` when available.
- Legacy `fields_json` remains synchronized so old paths do not break immediately.
- This keeps runtime stable while gradually completing full field-model cutover.

## Create page behavior
`admin/form_create.php` now provides a first-class field setup experience during creation:
- Builtin fields (`name`, `tel`, `email`, `message`) are rendered by default and marked non-deletable.
- Custom fields can be added directly before first save.
- Create-time field settings support:
  - label
  - required
  - enabled
  - placeholder
  - options (`select`)
  - display width
  - sort order
- Create submit persists in one coherent flow:
  - `forms`
  - `form_fields` (+ compatibility sync to `forms.fields_json`)
  - `site_settings`
