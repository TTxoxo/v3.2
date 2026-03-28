# REVIEW_EXECUTION_PLAN.md

## Scope of this round (audit only)
Audited latest current PR head implementation for:
- `admin/form_create.php`
- `admin/form_edit.php`
- `admin/_fields.php`
- `admin/_ui.php`
- `api/get_form.php`
- `api/submit.php`
- `embed/embed.js`
- `database/migrations/*.sql`

No broad rewrite is done in this round.

---

## 1) Confirmation of requested findings (actual current state)

### A. create page still does not directly support builtin + custom field creation
- **Status: FIXED IN CURRENT ROUND**
- Evidence:
  - create page now directly renders builtin rows, supports add custom field rows, and allows editing field settings before first save. (`admin/form_create.php`)

### B. edit page has field row index mismatch risk
- **Status: FIXED IN CURRENT ROUND**
- Evidence:
  - create/edit now submit row-based nested fields payload (`fields[row_id][...]`), decoupling checkbox states from fragile numeric indexes.
  - backend collector supports robust row-based parsing.

### C. create/edit flows are not transaction-safe
- **Status: FIXED IN CURRENT ROUND**
- Evidence:
  - create flow wraps multi-write persistence in transaction boundary.
  - edit flow now wraps multi-write persistence in transaction boundary with rollback on failure.

### D. edit flow lacks friendly one-form-per-site validation
- **Status: FIXED IN CURRENT ROUND**
- Evidence:
  - edit flow now performs explicit conflict pre-check (`site_id` target already having another form) and returns business-readable error before DB constraint failure.

### E. submit rate-limit query lacks ideal composite index
- **Status: CONFIRMED PRESENT**
- Evidence:
  - runtime query: `COUNT(*) WHERE site_id + user_ip + created_at >= ...` in `api/submit.php`.
  - migration currently adds only `idx_inquiries_tel` and `idx_inquiries_status`; no dedicated `(site_id, user_ip, created_at)` index.

### F. form_fields vs fields_json is still dual-source
- **Status: CONFIRMED PRESENT (intentional compatibility bridge)**
- Evidence:
  - `api/get_form.php` and `api/submit.php` load from `form_fields` first, fallback to `forms.fields_json`.
  - admin save still synchronizes back to `forms.fields_json` for compatibility.

### G. backend UI is only partially unified
- **Status: CONFIRMED PRESENT**
- Evidence:
  - `_ui.php` provides common shell/layout styles.
  - feature pages still contain substantial page-local inline CSS blocks (`form_create`, `form_edit`, etc.).

### H. embed.js still has duplicate-init / repeated-listener risk
- **Status: CONFIRMED PRESENT**
- Evidence:
  - fixed host id `inquiry-embed-host` is reused.
  - floating mode registers `document.addEventListener('keydown', ...)` on each script init and does not unbind.
  - no global singleton/idempotency guard for repeated script injection.

---

## 2) Items already fixed recently (DO NOT re-change in next phase unless regression appears)

1. `api/submit.php` preflight CORS response is now explicit and complete for `OPTIONS`.
2. `api/get_form.php` preflight CORS response has been aligned and completed for `OPTIONS`.
3. `config/database.php` now requires DB env vars (credential fallback removed).
4. `admin/login.php` has persistent login throttling (`login_attempts`) with old-env session fallback.
5. `api/inquiry_submit.php` remains explicit `410` deprecation stub.
6. `admin/form_create.php` now has direct builtin/custom field setup and coherent multi-entity save flow.
7. `admin/form_edit.php` now has transaction-safe save flow + friendly one-form-per-site pre-check.
8. create/edit field submission now uses robust row-based nested structure.

These should be treated as stabilized baseline unless regression evidence appears.

---

## 3) Files to modify in next phases

### Phase-1 (correctness + safety, highest priority)
- `admin/form_edit.php`
- `admin/form_create.php`
- `admin/_fields.php`

### Phase-2 (submit performance)
- `database/migrations/` (new additive migration file)
- `api/submit.php` (query unchanged or lightly adapted after index addition)

### Phase-3 (embed runtime hardening)
- `embed/embed.js`

### Phase-4 (UI consistency cleanup)
- `admin/form_create.php`
- `admin/form_edit.php`
- optional shared admin UI style helper/file if introduced minimally

### Phase-5 (compatibility bridge reduction plan, not immediate removal)
- `api/get_form.php`
- `api/submit.php`
- `admin/_fields.php`
- docs: `REFACTOR_PLAN.md`, `FINAL_CHECK.md`, `RELEASE_NOTES.md`

---

## 4) Suggested implementation order (next rounds)

1. **Transaction + friendly validation first**
   - Add pre-check for target site form conflict in `form_edit`.
   - Wrap create/edit multi-write flows in DB transaction.
   - Ensure rollback on any field/form/site_settings write failure.

2. **Field-row keying stabilization**
   - Replace positional checkbox keys with stable per-row token/key-based names.
   - Align backend parser to key-based structures to remove sparse-index ambiguity.

3. **Submit hot-path index migration**
   - Add additive index for rate-limit query (`site_id`, `user_ip`, `created_at`).
   - Keep backward-safe migration style with existence guard.

4. **Embed init hardening**
   - Add singleton/guard to prevent duplicate mount for same key/mode/target.
   - Avoid repeated global keydown binding or make binding idempotent.

5. **UI consolidation pass**
   - Move repeated local CSS fragments into shared admin style layer incrementally.

6. **Dual-source convergence planning (no risky immediate removal)**
   - Keep compatibility now; add explicit deprecation checkpoints before removing `fields_json` fallback paths.

---

## 5) Execution checklist for next implementation round

- [x] `form_edit` friendly one-form-per-site conflict message before DB write
- [x] edit flow transaction boundary with rollback (create flow already upgraded this round)
- [x] stable key-based field row payload parsing
- [ ] additive index migration for submit rate limiting query
- [ ] embed duplicate-init and repeated-listener guard
- [ ] docs update reflecting what changed vs what remains compatibility-only
