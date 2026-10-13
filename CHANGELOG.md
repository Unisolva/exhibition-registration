# Changelog

All notable changes. Versions follow semantic versioning; each release is the git tag `v<version>`.

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
