# REFACTOR_PLAN.md

## Refactor strategy
Low-risk, incremental, reversible changes only. Keep lightweight PHP architecture (no heavy framework migration).

## Goals for this phase
- Unify to one official public submit endpoint with strict validation.
- Preserve current behavior while fixing data-loss/security gaps.
- Prepare schema evolution with backward-compatible migrations.

---

## Incremental plan

### Phase 0 — Baseline and safety nets (no behavior change)
1. Snapshot current SQL schema and endpoint map in docs.
2. Add migration folder structure (`database/migrations`) if missing.
3. Add a rollback note for each migration file.
4. Add/refresh changelog tracking (`CHANGELOG_REFACTOR.md`) for each phase.

**Why low-risk**: docs + migration scaffolding only.

---

### Phase 1 — Submit endpoint consolidation (highest priority)
1. Declare `api/submit.php` as the single official public submit endpoint.
2. Convert `api/inquiry_submit.php` into a safe compatibility wrapper:
   - either reject with deprecation message and 410/400, or
   - internally delegate to shared submit service with same validation path.
3. Update `index.php` route listing to official endpoint only.
4. Keep response compatibility (`status`/`success`) during transition window.

**Acceptance checks**
- Only one code path can write `inquiries`.
- Legacy callers receive controlled, documented behavior.

**Why low-risk**: preserve URL availability initially while removing logic duplication.

---

### Phase 2 — Strict API key + origin/domain validation
1. Reuse the host normalization logic for submit endpoint.
2. In `submit.php`, require BOTH:
   - valid API key bound to a site,
   - validated origin/referer host matching normalized site domain rules.
3. Reject missing or invalid origin context by policy (configurable strict mode, default strict in production).
4. Stop blindly reflecting arbitrary `Origin`; emit CORS allow-origin only when validated.

**Acceptance checks**
- Cross-site submissions with valid API key but foreign origin are rejected.
- Missing origin strategy is explicit and documented.

**Why low-risk**: isolated to request validation; does not alter admin data model.

---

### Phase 3 — Custom field persistence correctness
1. Add migration to `inquiries`:
   - `payload_json JSON NULL` (or TEXT with JSON validation fallback if needed).
2. In submit flow:
   - keep dedicated columns for builtin fields (`name/tel/email/message`),
   - collect all non-builtin fields into `payload_json`.
3. Ensure inquiry detail page can render payload custom fields safely.
4. Keep fallback compatibility if column absent during rollout (temporary branch), then remove after migration adoption.

**Acceptance checks**
- Custom fields submitted from frontend are persisted and queryable.
- Builtin fields remain in dedicated columns.

**Why low-risk**: additive schema change; does not break existing columns.

---

### Phase 4 — One-form-per-site convergence
1. Introduce migration path toward one-per-site forms:
   - data audit: detect sites with >1 forms,
   - choose active form deterministically (latest by id),
   - archive/deactivate extras (soft strategy) before adding unique constraint.
2. Add unique index on `forms.site_id` once data is clean.
3. Update admin create flow:
   - if form exists for site, redirect to edit instead of creating new.

**Acceptance checks**
- DB enforces one form per site.
- Frontend loading logic and admin UX are consistent.

**Why low-risk**: staged cleanup before constraint enforcement avoids hard breaks.

---

### Phase 5 — Secrets hygiene and SMTP safety
1. Remove hardcoded DB password fallback from source; require env-based secrets.
2. In admin SMTP UI:
   - hide password by default (`type=password`),
   - avoid echoing raw password unless explicitly revealed.
3. Ensure logs never include raw secrets.

**Acceptance checks**
- No source-controlled default secrets.
- SMTP password is not displayed in plaintext by default.

**Why low-risk**: config/UI hardening with minimal behavior impact.

---

### Phase 6 — Timezone unification hardening
1. Centralize timezone bootstrap once (prefer bootstrap include for admin pages).
2. Normalize admin display formatting through helper (e.g., `format_admin_time($ts)` in Asia/Shanghai).
3. Ensure CSV/export timestamps are explicitly Asia/Shanghai.

**Acceptance checks**
- All admin pages and exports show consistent +08:00 semantic time.

**Why low-risk**: presentation-layer consistency and bootstrap cleanup.

---

### Phase 7 — Schema convergence toward target lightweight architecture
1. Introduce `form_fields` table and gradual migration from `fields_json`.
2. Introduce `inquiry_logs` naming convergence (or migration alias from `form_logs`).
3. Add `login_attempts` table for persistent rate limiting.
4. Keep compatibility reads during transition; remove clutter after successful backfill.

**Acceptance checks**
- Core target tables exist and are used.
- Legacy compatibility code is reduced with controlled rollback path.

---

## Proposed execution order
1. Phase 1 (endpoint unification)
2. Phase 2 (strict validation)
3. Phase 3 (payload_json custom field persistence)
4. Phase 4 (one-form-per-site enforcement)
5. Phase 5 (secrets hygiene)
6. Phase 6 (timezone hardening)
7. Phase 7 (table/model convergence)

---

## Rollback approach
- Each migration must have a rollback SQL block.
- Keep legacy read compatibility for one release window before deletion.
- Feature flags for strict-origin mode and legacy endpoint compatibility during rollout.

---

## Non-goals
- No migration to Laravel/ThinkPHP/Vue/React or other heavy frameworks.
- No full rewrite.
- No silent feature removals.

---

## Implemented DB migration sequence (this round)

### Execution order
1. `database/migrations/20260324_001_schema_additive.sql`
2. `database/migrations/20260324_002_data_backfill_and_form_convergence.sql`
3. `database/migrations/20260324_003_constraints_and_compatibility.sql`

### What this sequence does
- Adds target tables: `site_users`, `form_fields`, `inquiry_logs`, `login_attempts`.
- Adds inquiry structural fields: `tel`, `payload_json`, `legacy_form_id`, `status`.
- Backfills data:
  - `inquiries.tel <= inquiries.phone`
  - `inquiry_logs <= form_logs`
  - `form_fields <= forms.fields_json`
- Ensures builtin fields exist in every form (`name`, `tel`, `email`, `message`).
- Converges `forms` to one-form-per-site using deterministic rule (`MAX(forms.id)` as canonical).
- Preserves duplicate forms in `forms_archive`.
- Preserves historical inquiry form linkage through `inquiries.legacy_form_id`.
- Enforces one-form-per-site with unique key `uk_forms_site_id`.
- Enforces one-site-user-per-site via `uk_site_users_site_id`.
- Adds compatibility triggers so existing `form_logs` writes continue syncing into `inquiry_logs`.

### Rollback notes (operator-level)
- If rollback is needed before app code is switched:
  - Drop `uk_forms_site_id` to temporarily allow multi-form per site again.
  - Keep `forms_archive` + `inquiries.legacy_form_id` as source for restoring old mapping.
  - Drop compatibility triggers `trg_form_logs_ai` and `trg_form_logs_au` if they interfere with rollback.
  - New tables can be retained safely even when unused by old code.

### Compatibility caveat
- Current runtime code still reads `forms.fields_json` and writes `form_logs`.
- This migration keeps those paths functional while introducing target tables and sync bridge.

---

## Submit/security implementation status (this round)

### Completed now
- Phase 1 (submit endpoint consolidation): completed.
  - Official endpoint fixed at `/api/submit.php`.
  - Legacy `/api/inquiry_submit.php` moved to explicit deprecation (`410`).
- Phase 2 (strict validation): completed for submit path.
  - API key + strict origin/domain + site/form relationship validation enforced.
- Phase 3 (custom payload correctness): completed for submit path.
  - Builtin fields explicitly mapped to inquiry dedicated columns.
  - Custom fields validated against form definitions and persisted to `payload_json`.

### Compatibility retained intentionally
- `form_logs` write path retained for runtime stability.
- `inquiry_logs` remains synced via DB trigger bridge until later code cutover.

---

## Admin fields/timezone implementation status (this round)

### Completed now
- Admin form editor normalized around explicit `field_key` model (builtin vs custom separation).
- Builtin fields are enforced as non-deletable system fields in admin logic.
- Inquiry pages now render custom fields from `payload_json` with label mapping.
- Admin-visible timestamps unified through shared Asia/Shanghai formatting helpers.

### Compatibility retained intentionally
- `forms.fields_json` is still synchronized as a backward-compatible bridge while `form_fields` is primary.

---

## Frontend embed UI implementation status (this round)

### Completed now
- Inline embed mode upgraded to clean card-style, conversion-oriented UI.
- Floating mode upgraded with lightweight animation, explicit close controls, and mobile bottom-sheet behavior.
- Responsive layout and interaction states polished without introducing heavy framework dependencies.
- Submission compatibility with official secure endpoint preserved.

### Compatibility retained intentionally
- Server-side UI configuration persistence is still partial; embed supports safe overrides (query params + server/default merge) without breaking existing deployments.

---

## Final stabilization status (2026-03-24)

This final round intentionally avoided new feature work and broad redesign. Focus was release-readiness validation, cleanup, and handoff quality.

### Completed in stabilization round
- Final rule-by-rule verification against business constraints.
- Regression-oriented static checks across PHP and embed JS.
- Legacy classification finalized (removed / deprecated / retained-for-compatibility).
- Delivery docs finalized for review and staged deployment:
  - `FINAL_CHECK.md`
  - `RELEASE_NOTES.md`
  - `CHANGELOG_REFACTOR.md`
  - `README.md` consistency refresh

### Deferred intentionally (to keep risk low)
- Full runtime/UAT automation and browser-driven E2E tests.
- Production removal of compatibility bridges (`fields_json`, `form_logs` bridge, deprecated endpoint stubs).

### Release readiness statement
- Repository is ready for review and staged deployment.
- Production go-live remains gated by:
  1) operator-side migration dry run,
  2) manual UAT signoff in staging,
  3) rollback checkpoint confirmation.
