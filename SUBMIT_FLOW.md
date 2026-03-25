# SUBMIT_FLOW.md

## Official public submit endpoint
- **Official endpoint:** `POST /api/submit.php`
- Legacy `POST /api/inquiry_submit.php` is now explicitly deprecated and returns `410 Gone`.
- CORS preflight (`OPTIONS`) now returns complete CORS headers for cross-origin embed requests.
- `GET /api/get_form.php` preflight (`OPTIONS`) also returns complete CORS headers for cross-origin embed loading.

## High-level flow
1. Method / payload guard (`POST`, JSON parse, payload-size limit).
2. API key lookup (`sites.api_key`).
3. Strict origin-domain validation (Origin/Referer host normalized and matched to site domain).
4. Form ownership validation (`forms.id` belongs to `site_id`).
5. Lightweight anti-abuse checks:
   - honeypot (`website`/`company_website`) must be empty
   - per-site+IP frequency check in recent 10 minutes
6. Field definition load and validation:
   - preferred source: `form_fields` (`is_active=1`)
   - compatibility fallback: `forms.fields_json`
   - builtin fixed fields always enforced: `name`, `tel`, `email`, `message`
7. Builtin/custom mapping:
   - builtin -> dedicated columns
   - custom -> `inquiries.payload_json`
8. Insert inquiry (fallback SQL kept for old schema compatibility).
9. Downstream integrations (mail / GA4 / Ads) executed after successful insert.
10. Integration statuses persisted to `form_logs` (and mirrored to `inquiry_logs` by DB trigger).

## Validation order details

### 1) API key and site
- Reads `X-API-KEY` first, then JSON `api_key` fallback.
- Rejects missing/invalid key (`422` / `401`).

### 0) CORS preflight
- `OPTIONS` requests return:
  - `Access-Control-Allow-Origin`
  - `Vary: Origin`
  - `Access-Control-Allow-Methods`
  - `Access-Control-Allow-Headers`
- Strict origin/site/api_key checks remain enforced on actual `POST` request processing.

### 2) Origin/domain matching
- Extracts host from `Origin`, fallback `Referer`.
- Normalizes host (`lowercase`, strip `www.`).
- Normalizes site host from `sites.domain`.
- Accepts exact host or subdomain of site host.
- Rejects missing/invalid origin context (`403`).

### 3) Form/site relationship
- Requires `form_id`.
- Verifies form exists and `forms.site_id == sites.id`.
- If payload includes `site_id`, it must match API-key site.

### 4) Field definitions and data
- Loads definitions from `form_fields`; fallback to `fields_json` when needed.
- Ensures builtin fields exist even under fallback.
- Required fields are enforced using definition metadata.
- `email` format validated.
- `tel` basic pattern validated when non-empty.

## Builtin/custom storage

### Builtin fields (dedicated columns)
- `name` -> `inquiries.name`
- `tel` -> `inquiries.tel` (and mirrored to legacy `phone` for compatibility)
- `email` -> `inquiries.email`
- `message` -> `inquiries.message`

### Custom fields
- Only keys defined on the form are accepted as custom fields.
- Stored as structured JSON in `inquiries.payload_json`.

## Anti-abuse protections
- JSON body size limit (`50 KB`).
- Honeypot field check (`website` / `company_website`).
- Per-site+IP submission throttling (last 10 min count).

## Integration behavior and fault isolation
- Inquiry insert is primary transaction step.
- Mail/GA4/Ads failures are isolated and do not roll back the inserted inquiry.
- Failures are captured in status/error fields and write-safe logs.

## Compatibility notes
- `api/inquiry_submit.php` kept only as a hard deprecation stub (`410`) to avoid silent legacy acceptance.
- `form_logs` remains in use for runtime compatibility; DB trigger keeps `inquiry_logs` synchronized until full code cutover.
