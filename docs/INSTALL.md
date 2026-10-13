# Installing Exhibition Registration

A new installation on a cPanel hosting account, step by step. One installation serves one company. Everything specific to the company (name, logo, colour, country, ERP connection) is set in the app or in `.env`; no code changes.

Throughout, replace:

| Placeholder | Meaning |
| --- | --- |
| `exhibitions.example.com` | the hostname the company will use for the app |
| `ACCOUNT` | the cPanel account user |
| `APP` | `/home/ACCOUNT/apps/exhibition-registration` |
| `PHP_CLI` | the command-line PHP of the version the subdomain runs, e.g. `/opt/cpanel/ea-php84/root/usr/bin/php` |

## 1. Requirements

On the hosting account:

- SSH access as the account user, and cron.
- PHP 8.3 or newer for the subdomain (8.4 is what releases are built for), web and command line, with the extensions `pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo`, `tokenizer`, `xml`, `ctype`, `dom`, `iconv`. No GD, Imagick, Redis or Node is needed. The app works with the usual `disable_functions` list (`exec`, `proc_open`, `symlink` and similar).
- MariaDB 10.11 (or MySQL 8): one database and one user for this app only.
- If the company uses Odoo: outbound HTTPS from the account to the Odoo server.

On the machine that builds releases (never the server): git, PHP of the same minor version as the server, Composer.

## 2. Layout on the account

```
APP/
  releases/<YYYYmmddHHMMSS>/   one directory per release (code and vendor/)
  shared/.env                  configuration, mode 600, never inside a release
  shared/storage/              logs, sessions on disk, compiled views
  backups/                     database dumps taken before each deploy, mode 700
  current -> releases/<...>    switched by the deploy script
/home/ACCOUNT/public_html/exhibitions.example.com -> APP/current/public   (document root)
```

Only `public/` is reachable from the web. Code, `.env`, storage and backups stay outside every web root.

## 3. Step by step

### 3.1 Directories and a placeholder

```
mkdir -p APP/releases/0-placeholder/public APP/shared/storage APP/backups
chmod 700 APP/backups
echo '<!doctype html><title>Coming soon</title><p>Coming soon</p>' > APP/releases/0-placeholder/public/index.html
cd APP && ln -s releases/0-placeholder current
mkdir -p APP/shared/storage/app APP/shared/storage/logs APP/shared/storage/framework/cache/data \
         APP/shared/storage/framework/sessions APP/shared/storage/framework/views
```

### 3.2 Subdomain, document root and certificate

1. In cPanel, create the subdomain `exhibitions.example.com`. cPanel may only accept a document root inside `public_html`; use `public_html/exhibitions.example.com`.
2. Replace that directory with a symlink to the current release's `public/`:
   ```
   rmdir /home/ACCOUNT/public_html/exhibitions.example.com
   ln -s APP/current/public /home/ACCOUNT/public_html/exhibitions.example.com
   ```
3. Let AutoSSL issue the certificate. If a Cloudflare proxy is in front, the certificate may need the proxy off while it is issued.
4. In cPanel MultiPHP Manager, set the subdomain's PHP version **once, while the placeholder is current**. cPanel writes a handler block into `current/public/.htaccess`; the deploy script carries that block into every new release and refuses to deploy if it is missing. Do not set PHP INI values in MultiPHP: cPanel writes them into the release, and the next deploy drops them.

When the document root sits inside another site's `public_html`, that site's `.htaccess` is inherited and the app is also reachable as `https://other-site/exhibitions.example.com/`. The app's own `public/.htaccess` stops the inherited rewrite rules and page caching, and the app answers 404 to any host other than the one in `APP_URL`. Check both after the first deploy (section 5, V12 and V13).

### 3.3 Database

In cPanel MySQL Databases: create a database and a user for this app, give the user ALL PRIVILEGES on this database only, and set the database's collation to `utf8mb4_unicode_ci`. The app sets utf8mb4 on its own connection as well, so a server whose default is latin1 is fine.

### 3.4 Configuration: `APP/shared/.env`

Create it with mode 600 (`chmod 600 APP/shared/.env`):

```
APP_NAME="Exhibition Registration"
APP_ENV=production
APP_KEY=base64:...                 # PHP_CLI -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;'
APP_DEBUG=false
APP_URL=https://exhibitions.example.com
APP_LOCALE=ar                      # public form default language: ar or en
APP_FALLBACK_LOCALE=en
LOG_CHANNEL=daily
LOG_LEVEL=warning
DB_CONNECTION=mariadb
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...
SESSION_DRIVER=database
SESSION_LIFETIME=720
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
CACHE_STORE=database
QUEUE_CONNECTION=sync
MAIL_MAILER=log
# DEFAULT_COUNTRY=EG               # first default country for phone numbers and regions (a data file must exist)
# TRUST_CLOUDFLARE_HEADER=false    # see section 6
```

Keep a copy of `APP_KEY` with the company's other secrets: it encrypts the stored ERP API key, and a new key makes the stored one unreadable (the ERP screen then asks for it again). Never set `ERP_MOCK` on a server; the app refuses it in production. Deploys never change this file; add new keys by hand when a release note asks for them.

### 3.5 Build a release (off the server)

On the build machine, in a clone of the repository, with the release commit checked out and tagged `v<version>`:

```
bin/build-release.sh
```

This writes `build/release-<version>.tgz` and `build/release-<version>.tgz.sha256`. Copy both and `bin/deploy.sh` to the account, e.g. `scp -P <port> build/release-<version>.tgz* bin/deploy.sh ACCOUNT@server:`.

### 3.6 Deploy

Over SSH, as the account user:

```
PHP_CLI=/opt/cpanel/ea-php84/root/usr/bin/php bash deploy.sh release-<version>.tgz
```

The script checks the layout, the checksum, that the build is tagged and that the PHP handler block exists; dumps the database to `APP/backups/pre-<ts>.sql.gz`; extracts the release; links `storage` and `.env`; carries the handler block over; runs migrations, seeds product data (fields, regions, default settings; never users) and builds caches; switches `current` atomically; checks `/healthz`; and keeps the last three releases. If any step before the switch fails, the live release is untouched.

After the first deploy, open the site: `https://exhibitions.example.com/staff/login` must show the sign-in page.

### 3.7 First super admin

```
PHP_CLI APP/current/artisan user:create-super-admin --name="Full Name" --email="person@example.com"
```

It prints a one-time password once. Sign in, choose a new password, then:

- **Settings > Branding**: company name in English and Arabic, logo (PNG or SVG, at most 100 KB), brand colour (white text must stay readable on it), privacy page link, the "Powered by" switch.
- **Settings**: default country, display time zone, retention (months).
- **Settings > Form fields**: labels, required fields, occupation choices, custom fields.
- **Users**: admins and staff (each gets a one-time password).
- **Exhibitions**: create one, print its QR poster.

### 3.8 Cron

In cPanel Cron Jobs, as the account user:

```
* * * * * PHP_CLI APP/current/artisan leads:drain --max-items=25 --max-seconds=45 >/dev/null 2>&1
17 3 * * * PHP_CLI APP/current/artisan housekeeping:run >/dev/null 2>&1
```

The first sends queued leads to the ERP (it contacts the ERP only when something is waiting). The second deletes old run records, expired sessions, visitors 30 days in the trash, and visitors past the retention period. Both write their own log lines to `shared/storage/logs/drain-*.log`; cron output is discarded so it cannot fill the mailbox.

### 3.9 Odoo (optional)

Without an ERP, leave **ERP mode** on "None": the push screens stay hidden and the CSV export is the hand-off.

With Odoo 18:

1. In Odoo, create a dedicated user for the app, e.g. "Exhibition Registration (API)", with the Sales role **User: All Documents** and no other application. Not "Own Documents Only": Odoo hides leads assigned to someone else from such a user, so after a salesperson takes a lead the app could no longer recognise it, and a retry could create a duplicate. The user needs no delete right.
2. **Generate an API key for that user** (sign in to Odoo as that user, or as an administrator acting for it: Preferences > Account Security > New API Key) and use the key, not the user's password, in the app. The app's connector accepts either, but a key can be revoked on its own and is the method to use in production.
3. In the app, **Settings > ERP (Odoo)**: set ERP mode to Odoo, choose the Odoo version, and fill the **Live** block: Odoo URL (`https://` and the host only, no path), database name, the user's login, and the API key. The key is stored encrypted and never shown again ("Saved (ends …abcd)").
4. Press **Test connection**. It checks, in order: the server's version against the version selector, the login, that every lead field the app sends exists, and that the user can read and create leads and has "User: All Documents".
5. Select the Live block with its radio button, then switch **pushing** on.

A **Test** block for a staging Odoo is optional; while it is selected, every staff page shows a "TEST ERP" banner. Leads sent to Test can later be sent to Live once.

The app identifies itself to Odoo as `User-Agent: ExhibitionRegistration/<version>`. Some firewalls in front of Odoo block generic library agents; if the connection test fails with HTTP 403, check that firewall first.

## 4. Adding a country

Regions (governorates, states, provinces) come from a data file per country, `database/data/regions/<CC>.php`, where `<CC>` is the ISO 3166-1 alpha-2 code:

```php
<?php

return [
    'country_code' => 'EG',
    'field_label_en' => 'Governorate',      // label of the region field for this country
    'field_label_ar' => 'المحافظة',
    'regions' => [
        // code: stored on visitors; erp_code: exactly Odoo's res.country.state code
        ['code' => 'ALX', 'erp_code' => 'ALX', 'name_en' => 'Alexandria', 'name_ar' => 'الإسكندرية'],
        // ...
    ],
];
```

Add the file to the repository, release and deploy (the deploy seeds it), then choose the country under **Settings**. To check `erp_code` values against Odoo, read `res.country.state` for that country (`code` field).

## 5. Checks after the first deploy

| ID | Check | How | Pass |
| --- | --- | --- | --- |
| V1 | Account limits | as root: `lvectl list-user \| grep -w ACCOUNT` | recorded; enough for the expected traffic |
| V2 | PHP version of the subdomain | `uapi LangPHP php_get_vhost_versions` | 8.3 or newer |
| V3 | Command-line PHP | `PHP_CLI -v; PHP_CLI -r 'echo ini_get("disable_functions"), PHP_EOL;'; PHP_CLI -m` | same minor version; extensions of section 1 present |
| V4 | PHP the site runs | **System** page (super admin) | same version as V3, extensions present, release ID of the deploy |
| V5 | Outbound to Odoo | `PHP_CLI -r '$c=curl_init("https://ODOO_HOST/web/login");curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_TIMEOUT=>10]);curl_exec($c);echo curl_getinfo($c,CURLINFO_HTTP_CODE)," ",curl_error($c),PHP_EOL;'` | 200 or a redirect, no error |
| V6 | Client address | `tail -n 50 ~/access-logs/<site>-ssl_log \| awk '{print $1}' \| sort \| uniq -c \| sort -rn \| head` | real visitor addresses (see section 6) |
| V7 | Forms are never cached | `curl -sSI https://exhibitions.example.com/r/<token> \| grep -iE 'cf-cache-status\|cache-control\|x-litespeed-cache'` | `no-store`; no cache hit |
| V8 | Private files | `curl -s -o /dev/null -w '%{http_code}\n'` on `/.env`, `/storage/logs/laravel.log`, `/composer.json`, `/artisan` | 403 or 404 for each |
| V9 | Odoo version | `curl -sS https://ODOO_HOST/web/webclient/version_info -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","params":{}}'` | the version the selector names |
| V10 | Lead fields | the app's **Test connection** | passes all four steps |
| V11 | Reference search | on a staging Odoo only: push a test visitor, archive the lead, push again | no second lead |
| V12 | Another site's rules not inherited | `curl -sS https://exhibitions.example.com/staff/login` and `curl -sSI` on it | the sign-in page; no `x-litespeed-cache` header |
| V13 | Wrong host refused | `curl -s -o /dev/null -w '%{http_code}\n' https://other-site/exhibitions.example.com/staff/login` | 404 |

`https://exhibitions.example.com/healthz` returns `{"db":true,"drain_age_s":…,"queued":…}` and HTTP 503 when the database is unreachable or leads have been waiting more than 10 minutes without a run. Point the host's uptime monitor at it.

## 6. Client address and Cloudflare

Rate limits use the address the web server reports (`REMOTE_ADDR`). Forwarding headers are ignored by default, so a request cannot choose its own address. If the server is behind Cloudflare and does **not** already rewrite `REMOTE_ADDR` to the visitor's address (check with V6), set `TRUST_CLOUDFLARE_HEADER=true`: the app then reads `CF-Connecting-IP`, but only from connections that come from Cloudflare's published address ranges.

## 7. Backups and restore

Each deploy dumps the database to `APP/backups/pre-<ts>.sql.gz` (mode 600) before it changes anything. The host's own backups cover the rest; take a CSV export after each exhibition day as an extra copy. To restore a dump:

```
gunzip -c APP/backups/pre-<ts>.sql.gz | mysql --defaults-extra-file=<file with user and password> <database>
```

Going back to the previous release without touching the database: `bash deploy.sh --rollback`. See UPGRADE.md.

## 8. For developers

- `php artisan test` (PHPUnit, MariaDB 10.11). Tests that call a real Odoo read `ODOO_UAT_URL`, `ODOO_UAT_DB`, `ODOO_UAT_LOGIN`, `ODOO_UAT_KEY`, `ODOO_UAT_ADMIN_LOGIN`, `ODOO_UAT_ADMIN_KEY` from the environment and are skipped when they are unset; use a staging Odoo only. The installation-value test needs `INSTALLATION_DENYLIST`.
- Browser tests (Playwright and axe, run off the server): `tests/Browser`, fixtures from `tests/Browser/setup.php` (wipes its database; refuses without `BROWSER_TESTS_ALLOW_WIPE=1`).
