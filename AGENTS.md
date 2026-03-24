# AGENTS.md

## Project overview
This repository is a lightweight PHP multi-site foreign trade inquiry form management system.

The goal is to refactor the existing project into a stable, maintainable, production-ready version without introducing a heavy new framework.

## Core business rules
- Keep single-database multi-site architecture.
- Super admin can manage multiple sites.
- Each site has exactly one site user.
- Each site has exactly one main form.
- Builtin fixed fields must always exist:
  - name
  - tel
  - email
  - message
- Builtin fields must be stored in dedicated inquiry columns.
- Custom fields must be truly supported and saved into payload_json.
- Admin panel time display must use Asia/Shanghai.
- Frontend form must support:
  - inline mode
  - floating mode
- Frontend form must be responsive for desktop and mobile.

## Refactor priorities
1. Audit the real repository first before changing code.
2. Identify duplicate, dead, legacy, and unused code.
3. Consolidate multiple submit endpoints into one official public submit endpoint.
4. Require both API key validation and origin/domain validation.
5. Unify database structure and reduce historical compatibility clutter.
6. Improve maintainability with lightweight structure:
   - Controllers
   - Services
   - Repositories
   - Views
   - bootstrap
   - config
   - public
   - database/migrations
7. Keep the project lightweight PHP. Do not migrate the whole codebase to Laravel, ThinkPHP, Vue, React, or other heavy frameworks.
8. Prefer minimal-risk refactor over rewrite.

## Required database targets
The target database design should converge toward these core tables:
- admin_users
- sites
- site_users
- forms
- form_fields
- inquiries
- inquiry_logs
- system_settings
- site_settings
- login_attempts

## Database rules
- forms must be one-per-site.
- form_fields must be a dedicated table, not only fields_json.
- inquiries must contain dedicated columns for:
  - name
  - tel
  - email
  - message
- inquiries must include payload_json for custom fields.
- Add suitable indexes.
- Keep backward migration in mind when changing schema.

## Security rules
- Use prepared statements for DB queries.
- Escape output to prevent XSS.
- Add CSRF protection for admin forms.
- Add login rate limiting.
- Public submit endpoint must validate:
  - API key
  - origin / normalized host
- Avoid logging raw secrets.
- Move secrets out of source code and into environment-based configuration.
- Do not expose SMTP passwords in admin forms.
- Add basic anti-spam / anti-abuse checks for submit API.

## Frontend form requirements
- Support inline and floating modes.
- Responsive on mobile and desktop.
- Floating button should have light animation only:
  - hover effect
  - subtle pulse/breathing
  - open/close transition
- Style should be:
  - clean
  - business-like
  - B2B foreign trade friendly
  - conversion-oriented
- Allow admin-configurable:
  - title
  - subtitle
  - button text
  - success message
  - floating position
  - theme color
  - WhatsApp helper text

## Working style
- Read real files before making assumptions.
- Do not skip audit.
- Do not silently remove existing features.
- Explain why each important change is made.
- Keep changes incremental and reversible.
- Prefer small, reviewable commits.
- Update docs when changing behavior.
- Preserve current working behavior unless there is a clear reason to change it.

## Required deliverables
Create and maintain these files during the refactor:
- AUDIT.md
- REFACTOR_PLAN.md
- CHANGELOG_REFACTOR.md
- FINAL_CHECK.md

## Required workflow
1. Audit repository first.
2. Produce AUDIT.md.
3. Produce REFACTOR_PLAN.md.
4. Create migration SQL under database/migrations.
5. Implement changes incrementally.
6. Update CHANGELOG_REFACTOR.md after each major step.
7. Run self-checks and summarize in FINAL_CHECK.md.

## Definition of done
The task is done only if:
- audit is complete
- duplicate/dead code is identified
- obvious security issues are fixed
- database is unified
- builtin fields are fixed
- custom fields are truly stored
- admin timezone is unified to Asia/Shanghai
- inline and floating forms both work
- docs and self-test results are included
