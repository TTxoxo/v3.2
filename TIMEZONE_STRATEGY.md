# TIMEZONE_STRATEGY.md

## Objective
Ensure all **admin-facing time display** is consistently shown in `Asia/Shanghai`.

## Strategy
- Keep storage behavior unchanged for safety in this round.
- Centralize admin display conversion through shared helpers in `admin/_ui.php`:
  - `admin_timezone()`
  - `admin_format_datetime()`
  - `admin_now_filename()`

## Why centralized helpers
Before this round, time rendering used raw DB strings in multiple pages, which risks inconsistent display if server/session/db timezone differs.
A shared formatter avoids one-off conversion drift.

## Scope of applied display unification
Admin pages updated to format visible times through helper:
- dashboard
- sites list
- forms list
- inquiry list
- inquiry detail
- inquiry CSV export timestamp columns and filenames

## Notes on storage
- DB/session timezone settings remain as configured in existing bootstrap/database config.
- This round focuses on **consistent display layer behavior** without risky storage migration.
