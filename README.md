# Exhibition Registration

A self-hosted web application for agricultural companies that collect visitor details at exhibitions. It replaces paper sign-up sheets that rarely get typed in, and sends only the visitors a company chooses to its CRM as leads.

**Status: in development. There is no release yet.**

## What it does

- **One QR code per exhibition.** Visitors scan it and fill a short form on their phone. Arabic (right to left) and English.
- **Fast staff entry.** Staff enter visitors at the booth or from paper forms with Save & New. The exhibition stays selected between visitors, and a lost connection never loses what was typed or saves a visitor twice.
- **One visitor list** across all exhibitions, with search and filters. Repeat sign-ups of the same phone number at an exhibition are flagged, never merged.
- **Configurable form.** Administrators add their own fields (text or a list of choices) in Arabic and English without code changes.
- **Selected visitors only go to the CRM.** An administrator ticks the visitors to send and confirms. Each one becomes an Odoo lead. Nothing is sent automatically, and a visitor already sent is never sent twice.
- **Three roles.** Super admins configure the installation and its CRM connection; admins run exhibitions, users and sending to the CRM; staff enter visitors and see only their own entries.
- **The company's own brand.** Name, logo and colour are settings.

## CRM support

Odoo 18, through its external API. The administrator enters the Odoo URL, database, user and API key in the settings screen. The API key is stored encrypted and is never shown again after saving. A connection test runs before sending is allowed.

Without a CRM, the application still works: the CRM screens are hidden and visitors are exported to CSV instead.

## Built for shared hosting

- Laravel with server-rendered pages and very little JavaScript.
- PHP 8.3 or newer, MariaDB 10.11 or MySQL.
- No background workers, no Redis, no Node.js on the server. Sending to the CRM runs from a one-minute cron job in short, bounded runs.
- Works with PHP's process functions disabled (`exec`, `proc_open` and similar), as is common on shared hosting.

## Installation

Installation and upgrade guides will be published with the first release.

## Licence

Proprietary. The source code is public so it can be read and reviewed, but it may not be used, installed, copied or modified without a written agreement with Unisolva. See [LICENSE](LICENSE).

## About

Built by [Unisolva](https://unisolva.com/).
