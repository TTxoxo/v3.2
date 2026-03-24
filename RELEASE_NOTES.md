# RELEASE_NOTES.md

## 1) Refactor summary
This release finalizes a lightweight-PHP refactor for multi-site inquiry management with stabilization-first priorities:
- one official public submit endpoint,
- stricter submit security validation,
- structured field model + payload persistence,
- admin timezone consistency,
- improved inline/floating embed UX,
- additive migration path with compatibility bridges.

## 2) Database changes
- Added migration files:
  - `20260324_001_schema_additive.sql`
  - `20260324_002_data_backfill_and_form_convergence.sql`
  - `20260324_003_constraints_and_compatibility.sql`
- Added target tables:
  - `site_users`, `form_fields`, `inquiry_logs`, `login_attempts`, `forms_archive`
- Added inquiry structure:
  - `tel`, `payload_json`, `legacy_form_id`, `status`
- Enforced constraints:
  - one-form-per-site (`uk_forms_site_id`)
  - one-site-user-per-site (`uk_site_users_site_id`)
- Added compatibility sync triggers from `form_logs` to `inquiry_logs`.

## 3) Submit/security changes
- Official endpoint fixed to `POST /api/submit.php`.
- Legacy `POST /api/inquiry_submit.php` now explicit `410 Gone` deprecation stub.
- Added complete CORS preflight response handling for `OPTIONS` on `api/submit.php` to support browser cross-origin embed submission.
- Submit now requires both:
  - valid API key bound to site,
  - strict origin/referer host match to normalized site domain.
- Added anti-abuse checks:
  - payload size guard,
  - honeypot check,
  - per-site+IP short-window throttling.
- Builtin/custom storage split:
  - builtin fields (`name/tel/email/message`) -> dedicated columns,
  - custom fields -> `inquiries.payload_json`.

## 4) Admin field/timezone changes
- Introduced admin field helpers (`admin/_fields.php`) for builtin/custom normalization.
- Builtin keys are fixed, non-deletable, and force-preserved.
- Form editor now persists to `form_fields` and syncs `fields_json` for compatibility.
- Inquiry list/detail render custom payload with label mapping.
- Admin-visible time unified with Asia/Shanghai helper formatting.
- Admin login rate limiting now uses persistent `login_attempts` table (`username + ip + 15-minute window`) with session fallback for old deployments.

## 5) Frontend embed changes
- `embed/embed.js` supports both `inline` and `floating` modes.
- Responsive behavior improved for desktop/mobile.
- Floating mode supports subtle animation, close controls, and mobile bottom-sheet behavior.
- Submit states improved: loading/success/error and duplicate-submit guard.

## 6) Migration cautions
- Execute migrations sequentially.
- Perform full DB backup before migration.
- Validate post-migration invariants with queries from `DB_MIGRATION_NOTES.md`.
- Keep compatibility bridges during first rollout window.

## 7) Rollout notes
Recommended staged rollout:
1. Deploy code + docs.
2. Run migration 001, then verification queries.
3. Run migration 002, then verification queries.
4. Run migration 003, then verification queries.
5. Smoke test admin + submit + embed.
6. Monitor logs for deprecated endpoint and legacy embed usage.

## 8) Rollback notes
- Primary rollback strategy is full database restore from pre-migration backup.
- If partial rollback needed:
  - drop `uk_forms_site_id` to temporarily allow multi-form data repairs,
  - disable/remove compatibility triggers if they conflict with fallback behavior,
  - keep archived mapping (`forms_archive`, `legacy_form_id`) for restoration workflows.

## Legacy handling status
- **Deprecated:** `api/inquiry_submit.php` (explicit 410).
- **Deprecated compatibility stub:** `embed/form.js`.
- **Temporarily retained:** `forms.fields_json`, `form_logs` compatibility bridge.
- **Schema ready but not fully productized:** `site_users` lifecycle (currently structural enforcement, not a full site-user portal workflow).
