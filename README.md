# Foreign Trade Inquiry Manager (Lightweight PHP)

A lightweight multi-site inquiry form management system for B2B/foreign-trade scenarios.

## Current official public APIs
- `GET /api/get_form.php?key=...`
- `POST /api/submit.php`

Legacy endpoint:
- `POST /api/inquiry_submit.php` returns `410 Gone` and is retained only as an explicit deprecation stub.

## Admin entry
- `GET /admin/login.php`

## Refactor delivery docs
- `AUDIT.md`
- `REFACTOR_PLAN.md`
- `CHANGELOG_REFACTOR.md`
- `FINAL_CHECK.md`
- `RELEASE_NOTES.md`
- `DB_MIGRATION_NOTES.md`
- `SUBMIT_FLOW.md`
- `ADMIN_FIELDS.md`
- `TIMEZONE_STRATEGY.md`
- `FRONTEND_EMBED_UI.md`

## Migration files
- `database/migrations/20260324_001_schema_additive.sql`
- `database/migrations/20260324_002_data_backfill_and_form_convergence.sql`
- `database/migrations/20260324_003_constraints_and_compatibility.sql`

## Notes
- Keep architecture lightweight PHP (no heavy framework migration).
- Admin-visible time should be treated as Asia/Shanghai.
- Database connection requires environment variables: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`.
