# FINAL_CHECK.md

## 1) Architecture summary (final stabilization round)

This repository remains a lightweight PHP multi-site inquiry system with no heavy framework migration.

- Public API surface:
  - `GET /api/get_form.php`
  - `POST /api/submit.php` (official only write endpoint)
  - `POST /api/inquiry_submit.php` (deprecated `410` stub only)
- Admin surface:
  - `/admin/*` pages for site/form/inquiry management
- Embed surface:
  - `/embed/embed.js` supports `inline` and `floating` modes
- Data evolution path:
  - Additive migrations under `database/migrations`
  - Compatibility bridge retained where needed (`forms.fields_json`, `form_logs` bridge)

This round scope: final validation, regression checks, low-risk cleanup, delivery documentation, and release-readiness summary only.

---

## 2) Final rule-by-rule verification

### Rule 1: Single-database multi-site architecture preserved
- **Status:** PASS (code-level)
- **Evidence:** all site/form/inquiry operations are keyed around `sites.id` + `forms.site_id`; no per-site database split introduced.

### Rule 2: Each site has exactly one site user
- **Status:** PARTIALLY DONE (schema level complete, runtime productization deferred)
- **Evidence:** migration adds `site_users` with unique key on `site_id`.
- **Note:** current admin flows are still primarily super-admin oriented; full site-user lifecycle UI is not expanded in this stabilization round.

### Rule 3: Each site has exactly one main form
- **Status:** PASS
- **Evidence:** convergence migration deduplicates forms and adds unique index `uk_forms_site_id`; admin create flow redirects to edit when form exists.

### Rule 4: Builtin fixed fields always exist (`name`, `tel`, `email`, `message`)
- **Status:** PASS
- **Evidence:** admin field normalization + save helpers force-preserve builtin keys; migration backfill also ensures builtin presence.

### Rule 5: Builtin fields stored in dedicated inquiry columns
- **Status:** PASS
- **Evidence:** `api/submit.php` maps builtin values to dedicated columns (`name`,`tel`,`email`,`message`) and mirrors `tel` -> legacy `phone` for compatibility.

### Rule 6: Custom fields stored in `payload_json`
- **Status:** PASS
- **Evidence:** submit flow validates custom fields against form definitions and writes them into `inquiries.payload_json`; admin inquiry views read and render payload fields.

### Rule 7: Admin-visible time consistently shown in Asia/Shanghai
- **Status:** PASS (for targeted admin surfaces)
- **Evidence:** shared helper (`admin_format_datetime`) in `admin/_ui.php`; applied to dashboard/sites/forms/inquiries/inquiry_view and inquiry export naming.

### Rule 8: Only one official public submit endpoint exists
- **Status:** PASS
- **Evidence:** official endpoint is `POST /api/submit.php`; legacy `api/inquiry_submit.php` is hard-deprecated returning `410`.

### Rule 9: Submit enforces API key + origin/domain validation
- **Status:** PASS
- **Evidence:** `api/submit.php` checks API key/site binding and strict normalized origin host match against site domain; rejects invalid/missing origin context.

### Rule 9.1: Cross-origin preflight compatibility for official submit endpoint
- **Status:** PASS
- **Evidence:** `api/submit.php` `OPTIONS` now returns complete CORS preflight headers before exit.

### Rule 10: Frontend supports both inline and floating modes
- **Status:** PASS
- **Evidence:** `embed/embed.js` supports `display=inline` and default floating mode.

### Rule 11: Frontend responsive on desktop/mobile
- **Status:** PASS (code/CSS behavior check)
- **Evidence:** embed CSS includes desktop + mobile media rules, floating panel mobile bottom-sheet behavior, and responsive field grid.

---

## 3) Self-test checklist and results

> Environment limits: this container has no running web server/browser automation session and no MySQL client runtime integration test harness; validation is static/lint + code-path audit.

### Static/lint checks
- ✅ `php -l index.php config/config.php config/database.php`
- ✅ `for f in admin/*.php api/*.php api/helpers/*.php; do php -l "$f" || exit 1; done`
- ✅ `node --check embed/embed.js`

### Endpoint/route checks
- ✅ `rg -n "submit.php|inquiry_submit.php|get_form.php" index.php api/*.php SUBMIT_FLOW.md FINAL_CHECK.md RELEASE_NOTES.md README.md`
- ✅ `rg -n "410|Deprecated endpoint" api/inquiry_submit.php`

### Schema/migration consistency checks
- ✅ `rg -n "site_users|form_fields|inquiry_logs|login_attempts|payload_json|uk_forms_site_id|uk_site_users_site_id" database/migrations/*.sql DB_MIGRATION_NOTES.md`

### Builtin/custom field checks
- ✅ `rg -n "name|tel|email|message|payload_json|form_fields|admin_save_form_fields|admin_load_form_fields" admin/_fields.php api/submit.php admin/inquiries.php admin/inquiry_view.php`

### Timezone checks
- ✅ `rg -n "Asia/Shanghai|admin_format_datetime|admin_now_filename" admin/_ui.php admin/*.php TIMEZONE_STRATEGY.md`

### Legacy/dead code checks
- ✅ `rg -n "embed/form.js|/embed/form.js|inquiry_submit.php" -g '!vendor/**'`
- ✅ `rg -n "login_attempts|is_success|DATE_SUB\\(NOW\\(\\), INTERVAL 15 MINUTE\\)" admin/login.php database/migrations/*.sql`
- ✅ `rg -n "Access-Control-Allow-Origin|Access-Control-Allow-Methods|Access-Control-Allow-Headers|OPTIONS" api/submit.php`

---

## 4) Known limitations (honest final disclosure)

1. **No live DB migration execution in this environment**
   - Migration SQL reviewed and consistency-checked statically; actual `mysql` execution and production-volume performance need operator-side dry run.

2. **No browser automation screenshot in this environment**
   - Responsive/interaction verification is code/CSS-path based in this round; manual UAT in staging is still required before production rollout.

3. **`site_users` table is schema-ready but admin lifecycle remains minimal**
   - One-site-user constraint is enforced by DB schema, while full dedicated site-user management UX is not expanded in this stabilization-only round.

4. **Compatibility bridges intentionally retained**
   - `forms.fields_json` remains synchronized as compatibility bridge while `form_fields` is primary.
   - `form_logs` -> `inquiry_logs` trigger bridge retained during transition.
5. **`site_users` lifecycle remains schema-first**
   - Constraint is enforced at DB level; dedicated site-user login/portal workflow is still deferred.

---

## 5) Retained legacy/deprecated items

### Removed in prior rounds
- Legacy writable submit logic in `api/inquiry_submit.php` (replaced with deprecation-only behavior).

### Deprecated (explicit)
1. `api/inquiry_submit.php`
   - **Why retained:** prevent silent break for old clients with explicit migration message.
   - **Risk if removed now:** existing integrators may hard-fail without clear guidance.
   - **Future removal point:** next major release after migration notice window.

2. `embed/form.js`
   - **Why retained:** some old snippets may still reference it.
   - **Current behavior:** compatibility stub with deprecation warning only.
   - **Risk if removed now:** old embeds can break unexpectedly.
   - **Future removal point:** after embed snippet inventory confirms migration to `embed/embed.js`.

### Temporarily retained for compatibility
1. `forms.fields_json`
   - **Why:** backward compatibility for historical readers while `form_fields` is canonical.
   - **Risk if removed now:** old paths/tools relying on legacy JSON may fail.
   - **Removal point:** after one full release cycle with zero dependency.

2. `form_logs` write compatibility with `inquiry_logs` trigger sync
   - **Why:** gradual operational migration with minimal downtime risk.
   - **Risk if removed now:** legacy log readers/writers may lose data flow.
   - **Removal point:** once runtime reads/writes fully cut over to `inquiry_logs`.

---

## 6) Recommended next steps

1. Run migrations in staging with full backup and execute all SQL checks in `DB_MIGRATION_NOTES.md`.
2. Perform manual UAT checklist for admin login/sites/forms/inquiries and embed inline/floating on desktop + mobile browsers.
3. Monitor deprecated endpoint and legacy embed usage in logs.
4. Plan a compatibility-removal release once usage reaches near-zero.
5. Prepare staged production rollout with rollback checkpoint after each migration file.

---

## Final release-readiness judgement

- **Codebase state:** Stabilized for review and staged deployment.
- **Risk profile:** Moderate-low with compatibility bridges preserved.
- **Blocking items before production:** operator-side migration dry run + manual UAT signoff.

## 2026-03-25 follow-up verification (field-row correctness + edit transaction safety)

1. **Field row submission mismatch risk addressed?**
   - Yes. Create/edit pages now submit row-based nested field payload (`fields[row_id][...]`) to avoid fragile numeric checkbox index coupling.

2. **Create flow transaction-safe?**
   - Yes. Create flow wraps multi-entity writes in transaction and rolls back on failures.

3. **Edit flow transaction-safe?**
   - Yes. Edit flow now wraps field/form/site_settings writes in transaction and rolls back on failures.

4. **Friendly one-form-per-site validation in edit flow?**
   - Yes. Edit flow now checks for existing form on target site before save and returns business-readable error.

## 2026-03-25 follow-up verification (source-of-truth tightening + hot-path checks)

1. **Field-definition source of truth closer to `form_fields`?**
   - Yes. Admin/API runtime paths now query `form_fields` directly first; `fields_json` is fallback-only compatibility.

2. **Hot-path metadata checks reduced?**
   - Yes. Removed runtime `information_schema` table-existence checks from:
     - `admin/_fields.php`
     - `api/get_form.php`
     - `api/submit.php`

3. **Submit rate-limit query index coverage complete?**
   - Yes. Added migration `20260325_004_submit_rate_limit_index.sql` with composite index:
     - `inquiries(site_id, user_ip, created_at)`

4. **Compatibility tradeoff retained intentionally?**
   - Yes. `forms.fields_json` remains synchronized for legacy readers during migration window; primary authority is now `form_fields`.

## 2026-03-25 follow-up verification (backend UI consistency round)

1. **Shared backend style asset created and applied?**
   - Yes. Added `admin/assets/admin.css` and wired it in `admin/_ui.php` as the shared admin style foundation.

2. **Menu/topbar/buttons/forms/tables visually unified across target pages?**
   - Yes. `forms.php`, `sites.php`, `inquiries.php`, `form_create.php`, `form_edit.php`, and `dashboard.php` now use common style primitives from shared CSS.

3. **Inquiry detail readability improved with grouped sections?**
   - Yes. `inquiry_view.php` now groups content into clear cards for builtin fields, custom fields, source/tracking, and integration/log states.

4. **Page-local style duplication reduced?**
   - Yes. Removed large inline shell/page style blocks in core admin pages where safe, while keeping minimal page-specific behavior scripts.

5. **Functional regression introduced?**
   - No functional-path changes were intentionally introduced; this round focused on presentation-layer consistency and class-level markup cleanup.

## 2026-03-25 follow-up verification (embed duplicate-init safety round)

1. **Duplicate init on same page controlled?**
   - Yes. `embed/embed.js` now guards by same `api_key + mode + target` and returns early for repeated identical reinjection.

2. **Duplicate global keydown listener accumulation avoided?**
   - Yes. `Escape` listener is now bound once via shared global runtime state instead of one `document` listener per instance.

3. **Duplicate host mounting reduced for SPA-like reinjection?**
   - Yes. Per-instance host id plus existing-host checks now prevent repeated same-instance host creation.

4. **Inline/floating behavior preserved?**
   - Yes. Existing rendering, field mapping, submit flow, and UX paths are preserved; this round only adds lifecycle safety guards.
