# Exhibition Registration

A self-hosted web application for agricultural companies that collect visitor details at exhibitions. It replaces paper sign-up sheets that rarely get typed in, and sends only the visitors a company chooses to its CRM as leads.

**Status: version 1.2.0.** See [CHANGELOG.md](CHANGELOG.md).

## What it does

- **One QR code per exhibition.** Visitors scan it and fill a short form on their phone. Arabic (right to left) and English.
- **Fast staff entry.** Staff enter visitors face to face at the booth with Save & New. The exhibition stays selected between visitors, and a lost connection never loses what was typed or saves a visitor twice.
- **One visitor list** across all exhibitions, with search and filters. Repeat sign-ups of the same phone number at an exhibition are flagged, never merged.
- **Configurable form.** Administrators add their own fields (text or a list of choices) in Arabic and English without code changes.
- **Selected visitors only go to the CRM.** An administrator ticks the visitors to send and confirms. Each one becomes an Odoo lead. Nothing is sent automatically, and a visitor already sent is never sent twice.
- **Three roles.** Super admins configure the installation and its CRM connection; admins run exhibitions, users and sending to the CRM; staff enter visitors and see only their own entries.
- **The company's own brand.** Name, logo and colour are settings.

## CRM support

Odoo 18, through its external API. A super admin enters the Odoo URL, database, the login of a dedicated CRM-only Odoo user and that user's API key. The key is stored encrypted and is never shown again after saving. A connection test (version, login, lead fields, rights) must pass before sending is allowed. A separate Test connection for a staging Odoo is optional.

Without a CRM, the application still works: the CRM screens are hidden and visitors are exported to CSV instead.

## Built for shared hosting

- Laravel with server-rendered pages and very little JavaScript.
- PHP 8.3 or newer (releases are built for 8.4), MariaDB 10.11 or MySQL 8.
- No background workers, no Redis, no Node.js on the server. Sending to the CRM runs from a one-minute cron job in short, bounded runs.
- Works with PHP's process functions disabled (`exec`, `proc_open` and similar), as is common on shared hosting.

## Installation

- [docs/INSTALL.md](docs/INSTALL.md): a new installation on a cPanel hosting account, step by step, with the checks to run afterwards.
- [docs/UPGRADE.md](docs/UPGRADE.md): upgrading to a new release, and rolling back.

Releases are built off the server (`bin/build-release.sh`) and installed with `bin/deploy.sh`; the server needs no Composer and no Node.

## Licence

Proprietary. The source code is public so it can be read and reviewed, but it may not be used, installed, copied or modified without a written agreement with Unisolva. See [LICENSE](LICENSE).

## About

Built by [Unisolva](https://unisolva.com/).
