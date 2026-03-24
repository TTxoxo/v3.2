# DB_MIGRATION_NOTES.md

## Scope
This document explains operational sequencing, backfill behavior, and compatibility notes for the migration SQL introduced in this round.

## Migration order
Run in strict sequence:
1. `database/migrations/20260324_001_schema_additive.sql`
2. `database/migrations/20260324_002_data_backfill_and_form_convergence.sql`
3. `database/migrations/20260324_003_constraints_and_compatibility.sql`

## Pre-migration checklist
1. Full DB backup (schema + data).
2. Maintenance window recommended (form dedup updates and deletes are data-touching).
3. Confirm application is connected to the intended single database.
4. Confirm no long-running transactions on `forms`/`inquiries` tables.

## Data preservation strategy

### Forms deduplication
- Rule: keep newest form (`MAX(id)`) per site as canonical.
- Non-canonical forms are copied to `forms_archive` before deletion.
- `inquiries.form_id` referencing non-canonical forms are remapped to canonical form.
- Original form id is preserved in `inquiries.legacy_form_id`.

### Field model migration
- Existing `forms.fields_json` is parsed and backfilled into `form_fields`.
- Builtin fields are force-inserted if missing:
  - `name`
  - `tel`
  - `email`
  - `message`
- `fields_json` is retained for old code compatibility in this phase.

### Inquiry payload support
- `inquiries.payload_json` is added and initialized as `{}` for existing rows.
- This enables structured custom field persistence in subsequent code phase.

### Logs transition
- New `inquiry_logs` table is backfilled from `form_logs`.
- Compatibility triggers keep `inquiry_logs` synchronized when old code writes `form_logs`.

## Compatibility concerns
- Current runtime still reads from `forms.fields_json` and `form_logs`.
- Migrations are designed to avoid breaking current code before service-layer refactor.
- Target tables are introduced now; code cutover happens in later phase.

## Verification SQL (post-migration)

### 1) Ensure one form per site
```sql
SELECT site_id, COUNT(*) AS c
FROM forms
GROUP BY site_id
HAVING c > 1;
```
Expected: no rows.

### 2) Ensure one site user per site (constraint level)
```sql
SHOW INDEX FROM site_users WHERE Key_name = 'uk_site_users_site_id';
```
Expected: unique index exists.

### 3) Ensure builtin fields exist for all forms
```sql
SELECT f.id AS form_id,
       SUM(CASE WHEN ff.field_key = 'name' THEN 1 ELSE 0 END) AS has_name,
       SUM(CASE WHEN ff.field_key = 'tel' THEN 1 ELSE 0 END) AS has_tel,
       SUM(CASE WHEN ff.field_key = 'email' THEN 1 ELSE 0 END) AS has_email,
       SUM(CASE WHEN ff.field_key = 'message' THEN 1 ELSE 0 END) AS has_message
FROM forms f
LEFT JOIN form_fields ff ON ff.form_id = f.id
GROUP BY f.id
HAVING has_name = 0 OR has_tel = 0 OR has_email = 0 OR has_message = 0;
```
Expected: no rows.

### 4) Ensure custom payload structural support exists
```sql
SHOW COLUMNS FROM inquiries LIKE 'payload_json';
```
Expected: one row.

### 5) Ensure inquiry_logs populated
```sql
SELECT COUNT(*) AS c FROM inquiry_logs;
```
Expected: non-negative; should be >= `form_logs` count after backfill/sync.

## Rollback guidance (practical)
- Keep backup as primary rollback.
- If temporary rollback is required without full restore:
  1. Drop `uk_forms_site_id`.
  2. Restore form rows from `forms_archive` if needed.
  3. Repoint `inquiries.form_id` from `legacy_form_id` where appropriate.
  4. Drop sync triggers (`trg_form_logs_ai`, `trg_form_logs_au`) if disabling bridge.

## Tradeoffs
- We preserve compatibility with current code by keeping legacy structures during transition.
- This introduces short-term dual structures (`form_logs` + `inquiry_logs`, `fields_json` + `form_fields`) but minimizes production risk.
