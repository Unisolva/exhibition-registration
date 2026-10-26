# Upgrading Exhibition Registration

Every installation runs a tagged release. There are no per-installation branches or patches.

## Before

1. Read `CHANGELOG.md` for every version between the installed one (System page) and the new one. A release note says when a new `.env` key is needed or when a migration is **not** additive.
2. If push items are waiting or being processed, let them finish or cancel them first (the batch screen shows them). Not required, but it keeps the switch quiet.

## Upgrade

1. Build on the build machine: check out the tag `v<version>`, run `bin/build-release.sh`.
2. Copy `build/release-<version>.tgz`, its `.sha256` file and the new `bin/deploy.sh` to the account.
3. Over SSH: `PHP_CLI=<php> bash deploy.sh release-<version>.tgz`.
4. Open the **System** page as super admin: it must show the new release ID. If it still shows the old one, the web server is holding old PHP processes: stop and report it; do not deploy again. (On LiteSpeed the cure is usually restarting the account's PHP processes from WHM.)
5. Sign in as staff and open the Save & New screen; open the public form of an open exhibition.

The deploy script dumps the database first (`backups/pre-<ts>.sql.gz`), runs migrations and product seeding in the new release before switching, and leaves the live release untouched if any of that fails.

## Migrations

Migrations only move forward and are written to be safe on a database that holds real data. As long as they are additive (new tables, new nullable columns, new indexes), the previous release keeps working on the upgraded database, so a rollback needs no database restore.

A release whose migration is not additive says so in `CHANGELOG.md` and names the restore step. For such a release the rollback is the pre-deploy dump.

## Notes per version

- **1.1.0:** one additive migration, `exhibitions.default_locale` (Arabic or English, not null, default Arabic). A rollback to 1.0.0 needs no restore: 1.0.0 ignores the column and new rows get the default. After the upgrade every existing exhibition opens in Arabic; change it on the exhibition's Edit page. A logo uploaded before 1.1.0 is shown without width and height until it is uploaded again.

- **1.4.0:** no migration; the new setting `gift_text_color` starts empty, so the thank-you page looks as before. A rollback to 1.3.1 needs no restore.

- **1.3.1:** no migration. A rollback to 1.3.0 needs no restore.

- **1.3.0:** no migration. Staff pages load the vendored Tabler files from `public/assets/vendor/tabler/` (served by the same versioned-asset rule, now also for `vendor/<name>/<file>.min.<hash>.css|js`). A rollback to 1.2.0 needs no restore.

- **1.2.0:** two additive migrations: field settings (`audience`, `widget`) and the welcome package (exhibition settings, and on visitors `package_code`, `package_received_at`, `package_received_by`). The migration gives every existing visitor a package code; the seeding step of every deploy gives one to visitors added without it (for example by 1.1.0 after a rollback). A rollback to 1.1.0 needs no restore.

## Rollback

```
bash deploy.sh --rollback
```

Points `current` back at the previous release in `releases/` (the script keeps the last three). The database is not changed. If the release being left ran a non-additive migration, restore the dump taken before it:

```
gunzip -c <APP>/backups/pre-<ts>.sql.gz | mysql --defaults-extra-file=<file with user and password> <database>
```

where `<ts>` is the timestamp of the release being left (its directory name under `releases/`).
