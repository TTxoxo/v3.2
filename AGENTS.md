\# AGENTS.md



\## Project

This repository is a multi-site foreign trade inquiry form management system built with lightweight PHP architecture.



\## Core business rules

\- Single database, multi-site architecture.

\- Super admin manages multiple sites.

\- Each site has exactly one site user.

\- Each site has exactly one main form.

\- Fixed builtin fields:

&nbsp; - name

&nbsp; - tel

&nbsp; - email

&nbsp; - message

\- Custom fields must be supported and must be stored in database.

\- Admin panel must display China timezone: Asia/Shanghai.

\- Frontend form must support:

&nbsp; - inline mode

&nbsp; - floating mode

\- Frontend must be responsive.



\## Refactor priorities

1\. Audit existing code first.

2\. Remove duplicate and dead code.

3\. Consolidate multiple submit endpoints into one official submit endpoint.

4\. Add origin/domain validation for public submit API.

5\. Move toward unified database design:

&nbsp;  - admin\_users

&nbsp;  - sites

&nbsp;  - site\_users

&nbsp;  - forms

&nbsp;  - form\_fields

&nbsp;  - inquiries

&nbsp;  - inquiry\_logs

&nbsp;  - system\_settings

&nbsp;  - site\_settings

&nbsp;  - login\_attempts

6\. Keep fixed fields in inquiry columns and custom fields in payload\_json.

7\. Improve admin UI consistency and maintainability.

8\. Do not introduce a heavy new framework unless absolutely necessary.



\## Coding rules

\- Read real files before making assumptions.

\- Prefer minimal-risk refactor over rewrite.

\- Keep changes incremental.

\- Preserve current working behavior when possible.

\- Explain why each important change is made.

\- Use prepared statements for database queries.

\- Escape output to prevent XSS.

\- Use environment variables for secrets.

\- Avoid logging sensitive raw credentials.



\## Required deliverables

\- AUDIT.md

\- REFACTOR\_PLAN.md

\- migration SQL files

\- CHANGELOG\_REFACTOR.md



\## Done criteria

The task is complete only when:

\- duplicate/dead code is identified

\- obvious security issues are fixed

\- database is unified

\- builtin fields are fixed

\- custom fields are truly stored

\- admin timezone is unified to Asia/Shanghai

\- inline and floating forms both work responsively

\- docs and self-test results are included

