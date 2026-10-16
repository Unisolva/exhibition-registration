# Changelog

All notable changes. Versions follow semantic versioning; each release is the git tag `v<version>`.

## Unreleased

Release 1.1.0 (in progress): interface fixes from a usability and accessibility review.

- The public form no longer has a minimum fill time, a hidden trap field or a signed render time. Every valid submission is saved, including one sent a second after the page opened (for example with browser autofill), instead of being thrown away behind a "thank you" page. Rate limits, the request size cap, CSRF and the submission token still apply. "website" is now allowed as a custom field key.
- Save & New: the Retry and "Save as a new entry" buttons stay hidden until needed. After a save the message names the visitor and stays 4 seconds; a possible duplicate is a warning that stays until the next save, with a link to the earlier entry for admins and super admins. The message area keeps its height, so the form no longer jumps. A counter shows how many entries were saved on the page.
- Every date, number, URL and file field now has the same 44 px height and 16 px text as the other fields.
- The TEST ERP banner is inside the page header landmark.

- Release scripts only (no application change): `bin/deploy.sh` now leaves releases readable by the web server (directories 755, files 644, umask 022) and prunes old releases without process substitution, which CageFS does not support; `bin/build-release.sh` no longer packs an archive root of mode 700. Found on the first deploy, where the web server answered 404 for the whole site. Release 1.0.0 archives are deployed with the new `deploy.sh`.

## [1.0.0] - 2026-10-13

First release.

- Public registration per exhibition through a QR code: Arabic (right to left, default) and English, works without JavaScript, spam traps, rate limits, replay-safe submissions.
- Staff Save & New with retry that never saves a visitor twice or loses typed values; staff may enter visitors for closed exhibitions (paper forms).
- Visitor list with search (name or phone in any format, Arabic digits included), filters, possible-duplicate flags, CSV export (UTF-8 with BOM).
- Edit log, record lock once sent to the live ERP, 30-day trash, permanent deletion by super admins, retention.
- Three roles: super admin, admin, staff.
- Odoo 18 lead push: explicit selection, batches, a bounded cron drain that searches by reference before every create (no duplicate leads), retries with backoff, review of ambiguous cases, Test and Live connections, encrypted API key, connection test.
- Branding per installation (name, logo, colour), configurable form fields, region data per country (Egypt included).
- `/healthz`, System page, deploy and rollback scripts for cPanel hosting.

New `.env` keys: none required. Optional: `DEFAULT_COUNTRY`, `TRUST_CLOUDFLARE_HEADER`.
Migrations: initial schema.
