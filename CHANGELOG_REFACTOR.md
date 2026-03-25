# CHANGELOG_REFACTOR.md

## 2026-03-24 - Database refactor round (migration layer only)

### Added
- `database/migrations/20260324_001_schema_additive.sql`
- `database/migrations/20260324_002_data_backfill_and_form_convergence.sql`
- `database/migrations/20260324_003_constraints_and_compatibility.sql`
- `DB_MIGRATION_NOTES.md`

### Database changes
- Added target tables:
  - `site_users` (one-site-one-user unique constraint)
  - `form_fields` (field definition source-of-truth table)
  - `inquiry_logs` (target replacement for `form_logs`)
  - `login_attempts` (persistent rate-limit/audit support)
  - `forms_archive` (safe preservation for deduplicated forms)
- Added inquiry columns:
  - `tel` (builtin dedicated column)
  - `payload_json` (custom-field payload)
  - `legacy_form_id` (historical form mapping preservation)
  - `status`
- Added supporting indexes on inquiries (`tel`, `status`).

### Data migration and backfill
- Backfilled `inquiries.tel` from legacy `phone`.
- Backfilled `inquiry_logs` from existing `form_logs`.
- Backfilled `form_fields` from `forms.fields_json`.
- Enforced builtin field presence (`name`, `tel`, `email`, `message`) for every form.

### One-form-per-site convergence
- Deterministic rule: latest form (`MAX(id)`) per site becomes canonical.
- Duplicate forms are preserved in `forms_archive`.
- Inquiries linked to duplicate forms are remapped to canonical form and original id is kept in `inquiries.legacy_form_id`.
- Added unique constraint `uk_forms_site_id` on `forms(site_id)`.

### Compatibility handling
- Existing app still writes `form_logs`; added triggers to mirror into `inquiry_logs`.
- `fields_json` is preserved for compatibility while `form_fields` is introduced for migration path.

### Not done in this round
- No broad controller/frontend rewrite.
- No destructive drop of legacy tables/columns.

## 2026-03-24 - Submit flow consolidation + security hardening round

### Endpoint consolidation
- `api/submit.php` is the only official public submit endpoint.
- `api/inquiry_submit.php` changed to explicit deprecation response (`410 Gone`) with migration message.
- `index.php` route hints updated to public APIs (`/api/get_form.php`, `/api/submit.php`).

### Submit security and validation
- Added strict origin/domain validation to `api/submit.php` (API key is no longer sufficient by itself).
- Added method/payload guards: JSON parse validation + payload size ceiling.
- Added lightweight anti-abuse checks:
  - honeypot field check (`website`/`company_website`)
  - per-site+IP rate limit query window
- Added explicit site/form relationship checks and optional payload `site_id` consistency check.

### Field correctness and persistence
- Submit flow now loads field definitions from `form_fields` first (fallback `fields_json`).
- Builtin fixed fields are explicitly handled and validated (`name`, `tel`, `email`, `message`).
- Custom fields are validated against form definitions and stored into `inquiries.payload_json`.
- Builtin tel is saved into `inquiries.tel` and mirrored to legacy `phone` for compatibility.

### Integrations and runtime safety
- Mail/GA4/Ads execution is isolated after successful inquiry insert.
- Integration failures do not block core inquiry insertion.
- Integration failure info is captured into `form_logs` error/status fields.

### Embed and form-config compatibility
- `api/get_form.php` now reads field definitions from `form_fields` when available (fallback to `fields_json`).
- `embed/embed.js` payload now sends `tel` (and legacy `phone`) and includes a hidden honeypot field.

### Documentation
- Added `SUBMIT_FLOW.md` documenting official endpoint, validation order, origin logic, and builtin/custom storage strategy.

## 2026-03-24 - Admin field management + inquiry display + timezone unification round

### Admin field management
- Added `admin/_fields.php` helper library for builtin/custom field normalization and persistence.
- `admin/form_edit.php` refactored to:
  - treat builtin fields (`name`, `tel`, `email`, `message`) as non-deletable system fields,
  - manage custom fields with explicit `field_key`,
  - save settings (`placeholder`, `options`, `display_width`, `sort_order`) via `form_fields.settings_json`,
  - keep `forms.fields_json` synchronized for compatibility.
- `admin/form_create.php` now enforces one-form-per-site UX (existing site form redirects to edit) and initializes builtin system fields.

### Inquiry display improvements
- `admin/inquiries.php` updated to:
  - display tel via `COALESCE(i.tel, i.phone)`,
  - export custom fields from `payload_json` with label resolution,
  - format displayed/exported timestamps via shared Shanghai timezone helper.
- `admin/inquiry_view.php` updated to:
  - show custom fields from `payload_json` in readable labeled format,
  - continue showing source/tracking fields,
  - avoid raw payload dump as default display.

### Admin timezone unification
- Added shared timezone helpers in `admin/_ui.php`:
  - `admin_timezone()`
  - `admin_format_datetime()`
  - `admin_now_filename()`
- Applied helper-based Asia/Shanghai formatting in dashboard/sites/forms/inquiries/inquiry detail and inquiry export naming/timestamp output.

### Documentation
- Added `ADMIN_FIELDS.md`.
- Added `TIMEZONE_STRATEGY.md`.

## 2026-03-24 - Frontend embed UI upgrade round (inline + floating)

### Inline UI upgrade
- Rebuilt inline form rendering into a clean conversion-focused card layout.
- Added clearer labels, spacing, input/textarea/select styling, required markers, and state messaging.
- Added helper-text area support for business hints (e.g., WhatsApp note).

### Floating UI upgrade
- Upgraded floating widget to production-style trigger + popup panel with:
  - subtle breathing/hover effects,
  - explicit close control,
  - overlay close + ESC close,
  - smooth open/close transitions.
- Added mobile bottom-sheet style adaptation for better small-screen usability.

### Responsive and interaction polish
- Added responsive grid strategy (desktop 2-col where appropriate, mobile single-column).
- Improved focus-visible, hover, submitting, success, and error states.
- Prevented duplicate submit while request is in-flight.

### Compatibility and submit safety
- Maintained official secure submit endpoint compatibility (`/api/submit.php`).
- Builtin mapping now follows field keys first (`name`, `tel`, `email`, `message`).
- Custom field rendering/submission remains field-definition driven.
- Tracking context and legacy compatibility payload (`phone`) preserved.

### Documentation
- Added `FRONTEND_EMBED_UI.md` describing render structure, mode behaviors, responsiveness, animation, and config strategy.

## 2026-03-24 - Final stabilization + release-readiness round

### Validation and regression checks
- Performed repository-wide final verification focused on:
  - admin flows and timezone display consistency,
  - submit endpoint unification and security guards,
  - embed mode compatibility (`inline` + `floating`) and responsiveness,
  - migration/schema/code consistency for field and payload paths.
- Refreshed `FINAL_CHECK.md` into a release-handoff structure with:
  - architecture summary,
  - rule-by-rule verification,
  - self-test checklist,
  - known limitations,
  - legacy/deprecation registry,
  - next-step recommendations.

### Cleanup and legacy handling
- Kept `api/inquiry_submit.php` as explicit deprecation (`410`) to avoid silent breakage.
- Converted `embed/form.js` into a clear legacy compatibility stub with deprecation warning (no functional endpoint duplication introduced).
- Updated `README.md` from stale placeholder content to current API/admin/docs/migration references.

### Delivery documentation
- Added `RELEASE_NOTES.md` with rollout/rollback and migration cautions.
- Updated release/handoff docs to align with implemented codebase and stabilization scope.

## 2026-03-24 - Post-review hardening fixes round

### Submit CORS preflight compatibility
- `api/submit.php` now returns complete CORS headers for `OPTIONS` preflight requests:
  - `Access-Control-Allow-Origin`
  - `Vary: Origin`
  - `Access-Control-Allow-Methods`
  - `Access-Control-Allow-Headers`
- Strict origin/site/api-key validation remains enforced on actual `POST` submit path.

### Admin login persistent rate limiting
- `admin/login.php` now integrates `login_attempts` table for persistent throttling (`username + ip`, 15-minute window, max 5 failures).
- Added compatibility fallback to session-only counting when old deployments do not yet have `login_attempts`.

### Secrets hardening
- `config/database.php` no longer contains default DB credential fallbacks.
- DB connection now requires environment variables: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`.

### Mail notification completeness
- `api/submit.php` email content now includes a “自定义字段” section rendered from validated custom payload fields with label mapping.

### Documentation consistency
- Updated `SUBMIT_FLOW.md`, `FINAL_CHECK.md`, and `RELEASE_NOTES.md` to reflect:
  - CORS preflight behavior,
  - persistent admin login throttling status,
  - current compatibility posture (`form_logs`/`inquiry_logs`, `site_users` lifecycle scope).

## 2026-03-25 - Next-step cleanup follow-up

### CORS consistency cleanup
- `api/get_form.php` now returns complete CORS preflight headers on `OPTIONS` (origin/methods/headers/max-age + vary).
- Cross-origin embed form-loading behavior is now aligned with `api/submit.php` preflight handling style.

### Documentation semantics cleanup
- Updated verification language to clearly mark `site_users` as **partially done** (schema-level complete, runtime productization deferred), avoiding “fully done” ambiguity.

## 2026-03-25 - Create-page field product gap closure

### Admin create form UX
- `admin/form_create.php` now directly renders builtin system fields (`name`, `tel`, `email`, `message`) on create page.
- Builtin rows are clearly marked and non-deletable.
- Custom fields can be added directly during create flow (no longer edit-page-only setup).
- Create page now supports field settings editing: label / required / enabled / placeholder / options / display width / sort order.

### Coherent create persistence flow
- Create submit now persists `forms`, `form_fields` (plus compatibility `fields_json` sync), and `site_settings` in one coherent user flow.
- Added transaction handling in create flow to reduce partial-write risk from multi-entity persistence.

### Shared field-post parsing
- Added reusable helper in `admin/_fields.php` for collecting posted field rows.
- `admin/form_edit.php` now reuses this helper to reduce duplicated field-post parsing logic.

## 2026-03-25 - Field-row submission correctness + edit transaction/validation hardening

### Field submission structure hardening
- Refactored create/edit field form submission to robust row-based payload structure:
  - `fields[row_id][key]`
  - `fields[row_id][label]`
  - `fields[row_id][required]`
  - `fields[row_id][enabled]`
  - `fields[row_id][placeholder]`
  - `fields[row_id][options]`
  - `fields[row_id][display_width]`
  - `fields[row_id][sort_order]`
- This avoids fragile numeric index coupling for checkbox state after row delete/add operations.
- Added backward-compatible parser support in `admin/_fields.php` for legacy post shape.

### Transaction safety
- Create flow remains transaction-protected for multi-entity persistence.
- Edit flow now also uses transaction boundaries for form + field + site_settings persistence, with rollback on failure and user-friendly error.

### One-form-per-site friendly validation in edit flow
- Added explicit business pre-check in `admin/form_edit.php` to detect existing form on target site before save.
- Returns clear admin-facing error instead of relying on raw DB unique constraint failure.

## 2026-03-25 - Source-of-truth tightening + submit hot-path index round

### Source-of-truth policy tightening
- `admin/_fields.php`, `api/get_form.php`, and `api/submit.php` now query `form_fields` directly first.
- Removed hot-path `information_schema` table-existence checks from these runtime paths.
- `forms.fields_json` remains as compatibility fallback/output bridge only (legacy readers), not preferred runtime source.

### Submit hot-path DB improvement
- Added additive migration `database/migrations/20260325_004_submit_rate_limit_index.sql`.
- New composite index: `idx_inquiries_site_ip_created` on `inquiries(site_id, user_ip, created_at)`.
- Index shape aligns with submit anti-abuse query in `api/submit.php` (site + ip + recent time window).
