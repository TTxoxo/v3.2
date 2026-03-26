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

## 2026-03-25 - Backend admin UI consistency and visual cleanup round

### Centralized admin style foundation
- Added shared stylesheet: `admin/assets/admin.css`.
- Moved common backend visual tokens and components into centralized classes:
  - sidebar / topbar shell styles
  - panel/card styles
  - unified button variants (`btn-primary`, `btn-secondary`, `btn-danger`, `btn-success`)
  - form controls (`form-label`, `form-control`, checkbox group)
  - table/list styles (`table`, `table-wrap`, `table-fields`)
  - message/status styles (`msg` + badge variants)

### Shared shell cleanup
- `admin/_ui.php` now loads `/admin/assets/admin.css` and uses consistent nav icon alignment class.
- Removed large inline shell CSS block from `_ui.php` to reduce style duplication.

### Page-level consistency improvements
- Unified list pages (`forms.php`, `sites.php`, `inquiries.php`) around common page head, table, button, and message styles.
- Unified create/edit form pages (`form_create.php`, `form_edit.php`) with shared form/table/button primitives and reduced page-local style blocks.
- Improved inquiry detail readability (`inquiry_view.php`) by grouping into sections:
  - 基础信息
  - 自定义字段
  - 来源与追踪
  - 集成与日志状态
- Applied small login visual consistency tweak to consume shared brand color tokens.

## 2026-03-25 - Embed stability hardening (duplicate-init safety)

### Duplicate initialization guards
- `embed/embed.js` now uses a lightweight same-instance registry guard keyed by `api_key + mode + target` to short-circuit duplicate reinjection on the same page.
- Added deterministic per-instance host id (`inquiry-embed-host-...`) and existing-host checks to reduce duplicate mounting risk during SPA-like repeated script injections.

### Global listener accumulation control
- Replaced per-instance document `keydown` Escape binding with a shared global runtime (`window.__INQUIRY_EMBED_GLOBAL__`) that binds once and closes currently open floating panel entries.
- This prevents uncontrolled document-level keydown listener accumulation when embed script is injected repeatedly.

### Compatibility/behavior
- Inline and floating behavior/UX remain unchanged in normal single-init usage.
- Submit payload contract and secure endpoint usage remain unchanged (`POST /api/submit.php` + `X-API-KEY`).

## 2026-03-25 - Final validation and documentation alignment cleanup

### Scope
- Validation/consistency/cleanup only.
- No broad redesign and no new feature rollout in this round.

### Documentation alignment updates
- `FINAL_CHECK.md` expanded with explicit 12-point closure checklist:
  - create/edit field UX behavior
  - transaction safety
  - one-form-per-site validation
  - composite index presence
  - source-of-truth documentation
  - backend UI consistency
  - embed duplicate-init protection
  - docs-to-code alignment
- `RELEASE_NOTES.md` updated to include:
  - migration `20260325_004_submit_rate_limit_index.sql`
  - submit hot-path composite index details
  - embed duplicate-init/listener safety notes
  - final validation scope statement.

## 2026-03-26 - Fresh-install consolidated SQL bundle

### Added
- `multisite_inquiry_full_install.sql`

### Purpose
- Provide a one-shot, executable, final-state schema import for brand-new empty databases.
- Avoid migration-client incompatibilities in some environments (notably prepared-statement trigger creation limitations).

### Notes
- Keeps compatibility tables/bridges expected by current runtime (`fields_json`, `form_logs`, `inquiry_logs` sync triggers).
- Existing databases should continue to use incremental migration scripts.
