# AUDIT.md

## Audit scope
Repository audited directly (no rewrite yet), with focus on:
1. submit endpoints
2. custom-field persistence
3. per-site form cardinality vs frontend loading logic
4. hardcoded DB/SMTP/secrets
5. submit API origin/domain validation strictness
6. admin timezone consistency

## Repository snapshot (high-level)
- Backend: plain PHP scripts under `api/` and `admin/`.
- Frontend embed: `embed/embed.js`.
- DB schema baseline: `multisite_inquiry_schema.sql`.
- Config: `config/config.php`, `config/database.php`.

---

## Findings

### 1) Multiple submit endpoints exist ✅ (confirmed)

**Evidence**
- Public embed submits to `/api/submit.php`. (`embed/embed.js` sets `SUBMIT_API`).
- Another writable endpoint exists: `/api/inquiry_submit.php` and inserts directly to `inquiries`.
- `index.php` route hint still lists `/api/inquiry_submit.php`, indicating legacy/public visibility.

**Impact**
- Endpoint behavior is inconsistent:
  - `api/submit.php` checks `X-API-KEY` and form-site ownership.
  - `api/inquiry_submit.php` only requires posted `site_id/form_id/name/email` and has no API-key validation.
- This increases attack surface and bypass risk.

---

### 2) Custom fields are rendered on frontend but not truly stored as structured custom payload ✅ (confirmed)

**Evidence**
- Frontend renders arbitrary `fields_json` into inputs and includes extra keys in payload (`Object.keys(values)` append).
- Backend inserts only fixed columns (`name/email/phone/message` + attribution columns) and does not store unknown custom keys to `payload_json`.
- Schema has no `payload_json` column in `inquiries`.

**Impact**
- Custom field values are effectively dropped at persistence layer.
- Violates requirement: custom fields should be truly stored (e.g., `payload_json`).

---

### 3) System allows multiple forms per site while frontend always loads latest one ✅ (confirmed)

**Evidence**
- `forms` table has no unique constraint on `site_id`.
- Admin can create unlimited forms for any site (`form_create.php` inserts without uniqueness checks).
- Frontend config API loads one record only: `WHERE site_id = :site_id ORDER BY id DESC LIMIT 1`.

**Impact**
- Operational ambiguity: admin sees multiple forms, frontend silently serves latest only.
- Existing inquiries may relate to older forms while frontend behavior diverges.

---

### 4) Hardcoded DB/SMTP credentials or secrets ✅ (confirmed)

**Evidence**
- DB defaults include hardcoded password fallback in source (`DB_PASS` fallback literal).
- SMTP password is displayed in plain text in admin settings input (`value="...password..."`, no masking/read-protection).
- Site/API key is intentionally shown in admin (expected for embed), but secret hygiene is weak around DB and SMTP credentials.

**Impact**
- Source-level secret leakage risk.
- Admin UI shoulder-surf / accidental disclosure risk for SMTP password.

---

### 5) Submit API lacks strict origin/domain validation ✅ (confirmed)

**Evidence**
- `api/submit.php` reflects `Access-Control-Allow-Origin` from request origin but does not verify origin against site domain before processing.
- `api/get_form.php` has origin/domain normalization checks, but logic defaults to allow when origin is absent (`$isAllowedOrigin = true` and conditional check only when both hosts non-empty).
- Legacy `api/inquiry_submit.php` also lacks API key + strict origin checks.

**Impact**
- API key alone is treated as sufficient in `submit.php`; no strict host binding.
- Header reflection + permissive missing-origin behavior weakens anti-abuse posture.

---

### 6) Admin time display not fully unified to Asia/Shanghai ⚠️ (partially configured, not fully enforced)

**Evidence**
- Global config timezone is set to `Asia/Shanghai`.
- `database.php` sets default app timezone + DB session timezone.
- `login.php` explicitly sets timezone again.
- Most admin pages rely on DB timestamps printed directly and do not explicitly normalize/format display timezone at render-time.

**Assessment**
- Environment defaults likely produce +08:00 behavior, but display consistency depends on DB/session/environment assumptions and string rendering.
- Not a hard failure today, but not a single explicit and auditable display-time strategy across all admin pages.

---

## Additional structural gaps vs target architecture/rules

1. **Schema divergence from target tables**
   - Missing/unused target tables from requirement set (`site_users`, `form_fields`, `inquiry_logs`, `login_attempts`).
   - Existing table is `form_logs` (naming mismatch with target `inquiry_logs`).

2. **Builtin fixed fields are not schema-governed at form-definition level**
   - `fields_json` generated from admin labels; no strict guarantee that builtin fields (`name/tel/email/message`) always exist in form definition.
   - Backend requires `name` + `email`; `message`/`phone` optional and mapped heuristically from labels/types.

3. **Legacy compatibility clutter in submit path**
   - Dual insert SQL with fallback on unknown column suggests mixed historical schemas and compatibility branches.

4. **Login throttling exists only in session memory**
   - No persistent `login_attempts` table use, so distributed/long-window rate limiting is limited.

---

## Risk-ranked issues

### High
- Duplicate submit endpoints with inconsistent auth/validation.
- Missing strict origin/domain validation on submit endpoint.
- Custom fields not persisted in dedicated JSON payload.

### Medium
- One-site-many-forms ambiguity while frontend always selects latest.
- Hardcoded DB fallback credentials and SMTP password exposure in admin UI.

### Low/Medium
- Timezone handling mostly present but not uniformly explicit at display layer.

---

## Conclusion
The repository already has a lightweight PHP baseline and some good practices (prepared statements, CSRF in many admin forms). However, the six requested checks are largely validated as real issues, especially endpoint duplication, custom-field persistence loss, one-site-many-forms mismatch, and submit-origin validation weakness. A low-risk incremental refactor should prioritize endpoint unification and data correctness before UI/architecture cleanup.
