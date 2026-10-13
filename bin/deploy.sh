#!/usr/bin/env bash
# Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.
#
# Deploy a release on the hosting account (work order §11). Run over SSH as the account user:
#
#   bash deploy.sh release-<version>.tgz          deploy a tagged release
#   bash deploy.sh release-<...>-dev-<sha>.tgz --dev   deploy an untagged rehearsal build
#   bash deploy.sh --rollback                     point "current" back at the previous release
#
# Settings (environment variables, with their defaults):
#   APP_DIR   $HOME/apps/exhibition-registration   holds releases/, shared/, backups/ and current
#   PHP_CLI   /opt/cpanel/ea-php84/root/usr/bin/php  the PHP the subdomain runs (same minor version)
#   PHP_ARGS  (empty)                              extra PHP options, e.g. for a rehearsal
#   KEEP      3                                    releases to keep
#
# Steps: checks; database dump to backups/pre-<ts>.sql.gz; extract to releases/<ts>; link storage
# and .env; carry cPanel's PHP handler block into the new public/.htaccess; migrate, seed product
# data, build caches; switch "current" atomically; smoke test /healthz; keep the last releases.
# Nothing is switched unless every step before the switch succeeded.
set -euo pipefail
# Release files must be readable by the web server, which serves static files as another user
# (cPanel default: directories 755, files 644). Secrets stay protected by their own modes:
# shared/.env is 600, backups/ is 700, dumps are 600.
umask 022

APP_DIR="${APP_DIR:-$HOME/apps/exhibition-registration}"
PHP_CLI="${PHP_CLI:-/opt/cpanel/ea-php84/root/usr/bin/php}"
read -r -a PHP_EXTRA <<< "${PHP_ARGS:-}"
KEEP="${KEEP:-3}"
HANDLER_BEGIN='# php -- BEGIN cPanel-generated handler'
HANDLER_END='# php -- END cPanel-generated handler'

say()  { printf '\n== %s\n' "$*"; }
die()  { printf '\nSTOPPED: %s\n' "$*" >&2; exit 1; }
php_() { "$PHP_CLI" "${PHP_EXTRA[@]}" "$@"; }

# Value of one key from shared/.env (quotes removed). Never printed by this script.
env_value() {
  local line
  line="$(grep -m1 -E "^$1=" "$APP_DIR/shared/.env" || true)"
  line="${line#*=}"
  line="${line%\"}"; line="${line#\"}"; line="${line%\'}"; line="${line#\'}"
  printf '%s' "$line"
}

check_layout() {
  [ -d "$APP_DIR/releases" ] || die "$APP_DIR/releases is missing."
  [ -f "$APP_DIR/shared/.env" ] || die "$APP_DIR/shared/.env is missing."
  [ -d "$APP_DIR/shared/storage" ] || die "$APP_DIR/shared/storage is missing."
  [ -d "$APP_DIR/backups" ] || die "$APP_DIR/backups is missing."
  [ -L "$APP_DIR/current" ] || die "$APP_DIR/current is not a symlink."
  [ -x "$PHP_CLI" ] || die "PHP CLI not found at $PHP_CLI (set PHP_CLI)."
}

handler_block() {
  sed -n "/^$HANDLER_BEGIN/,/^$HANDLER_END/p" "$1" 2>/dev/null || true
}

rollback() {
  check_layout
  local current previous
  current="$(basename "$(readlink "$APP_DIR/current")")"
  previous="$(cd "$APP_DIR/releases" && ls -1 | sort | grep -B1 -x -- "$current" | head -1 || true)"
  [ -n "$previous" ] && [ "$previous" != "$current" ] || die "No release older than $current to roll back to."
  [ -n "$(handler_block "$APP_DIR/releases/$previous/public/.htaccess")" ] || echo "Note: releases/$previous has no cPanel PHP handler block."
  say "Rolling back: current -> releases/$previous (was releases/$current)"
  ( cd "$APP_DIR" && ln -sfn "releases/$previous" current.new && mv -T current.new current )
  echo "Done. The database was not changed. If releases/$current ran a migration that is not additive,"
  echo "restore the dump taken before it (backups/pre-$current.sql.gz) after reading docs/UPGRADE.md."
  exit 0
}

[ "${1:-}" = "--rollback" ] && rollback

TGZ="${1:-}"
DEV=0
[ "${2:-}" = "--dev" ] && DEV=1
[ -n "$TGZ" ] && [ -f "$TGZ" ] || die "Usage: bash deploy.sh release-<version>.tgz [--dev] | --rollback"
TGZ="$(cd "$(dirname "$TGZ")" && pwd)/$(basename "$TGZ")"

say "Checks"
check_layout
PHP_VERSION="$(php_ -r 'echo PHP_VERSION;')"
php_ -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' || die "PHP $PHP_VERSION is older than 8.3."
echo "PHP $PHP_VERSION at $PHP_CLI"
if [ -f "$TGZ.sha256" ]; then
  ( cd "$(dirname "$TGZ")" && sha256sum -c --quiet "$(basename "$TGZ").sha256" ) || die "Checksum does not match $TGZ.sha256."
  echo "Checksum OK"
fi
RELEASE_INFO="$(tar -xzOf "$TGZ" ./RELEASE 2>/dev/null)" || die "The archive has no RELEASE file; build it with bin/build-release.sh."
release_field() { printf '%s\n' "$RELEASE_INFO" | sed -n "s/^$1=//p"; }
RELEASE_ID="$(release_field id)"
echo "Release $RELEASE_ID (tagged: $(release_field tagged), built $(release_field built_at), PHP platform $(release_field php_platform))"
if [ "$(release_field tagged)" != yes ] && [ "$DEV" -eq 0 ]; then
  die "This build is not tagged. Deploy tagged releases only, or pass --dev for a rehearsal."
fi
PLATFORM_MINOR="$(release_field php_platform | cut -d. -f1-2)"
RUNNING_MINOR="$(printf '%s' "$PHP_VERSION" | cut -d. -f1-2)"
[ -z "$PLATFORM_MINOR" ] || [ "$PLATFORM_MINOR" = "$RUNNING_MINOR" ] || echo "Warning: built for PHP $PLATFORM_MINOR, server CLI runs $RUNNING_MINOR."

CURRENT_HTACCESS="$APP_DIR/current/public/.htaccess"
HANDLER="$(handler_block "$CURRENT_HTACCESS")"
[ -n "$HANDLER" ] || die "The current release's public/.htaccess has no cPanel PHP handler block; without it the subdomain falls back to another PHP version (work order §3). Fix the current release first."
echo "cPanel PHP handler block found ($(printf '%s\n' "$HANDLER" | grep -m1 -oE 'ea-php[0-9]+' || echo 'handler'))"

TS="$(date -u +%Y%m%d%H%M%S)"
NEW="$APP_DIR/releases/$TS"
[ ! -e "$NEW" ] || die "$NEW already exists."

say "1. Database dump"
DB_HOST="$(env_value DB_HOST)"; DB_PORT="$(env_value DB_PORT)"; DB_NAME="$(env_value DB_DATABASE)"
DB_USER="$(env_value DB_USERNAME)"; DB_PASS="$(env_value DB_PASSWORD)"
[ -n "$DB_NAME" ] && [ -n "$DB_USER" ] || die "DB_DATABASE or DB_USERNAME missing in shared/.env."
DEFAULTS="$(mktemp "$APP_DIR/backups/.client.XXXXXX")"
trap 'rm -f "$DEFAULTS"' EXIT
chmod 600 "$DEFAULTS"
escape() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'; }
printf '[client]\nuser="%s"\npassword="%s"\nhost="%s"\nport="%s"\n' \
  "$(escape "$DB_USER")" "$(escape "$DB_PASS")" "$(escape "${DB_HOST:-localhost}")" "$(escape "${DB_PORT:-3306}")" > "$DEFAULTS"
DUMP="$APP_DIR/backups/pre-$TS.sql.gz"
mysqldump --defaults-extra-file="$DEFAULTS" --single-transaction --quick --routines --triggers \
  --default-character-set=utf8mb4 "$DB_NAME" | gzip > "$DUMP"
chmod 600 "$DUMP"
gzip -t "$DUMP" || die "The dump $DUMP is damaged."
echo "$DUMP ($(du -h "$DUMP" | cut -f1))"

say "2. Extract to releases/$TS"
mkdir "$NEW"
tar -xzf "$TGZ" -C "$NEW" --no-same-owner
[ -f "$NEW/artisan" ] && [ -f "$NEW/public/index.php" ] || die "The archive does not look like a release."
# Whatever modes the archive carried: directories 755, files 644, artisan executable.
chmod 755 "$NEW"
find "$NEW" -type d -exec chmod 755 {} +
find "$NEW" -type f -exec chmod 644 {} +
chmod 755 "$NEW/artisan"
[ ! -e "$NEW/storage" ] && [ ! -e "$NEW/.env" ] || die "The archive contains storage/ or .env; rebuild it."
mkdir -p "$APP_DIR/shared/storage/app" "$APP_DIR/shared/storage/logs" \
  "$APP_DIR/shared/storage/framework/cache/data" "$APP_DIR/shared/storage/framework/sessions" \
  "$APP_DIR/shared/storage/framework/views"
ln -s ../../shared/storage "$NEW/storage"
ln -s ../../shared/.env "$NEW/.env"
# Carry cPanel's PHP handler block over (replace one already in the file, else append).
NEW_HTACCESS="$NEW/public/.htaccess"
sed -i "/^$HANDLER_BEGIN/,/^$HANDLER_END/d" "$NEW_HTACCESS"
printf '\n%s\n' "$HANDLER" >> "$NEW_HTACCESS"
[ -n "$(handler_block "$NEW_HTACCESS")" ] || die "Could not write the handler block into $NEW_HTACCESS."
echo "storage and .env linked; handler block carried over"

say "3. Migrate, seed product data, build caches"
(
  cd "$NEW"
  php_ artisan migrate --force --no-interaction
  php_ artisan db:seed --class=ProductSeeder --force --no-interaction
  php_ artisan config:cache --no-interaction
  php_ artisan route:cache --no-interaction
  php_ artisan view:cache --no-interaction
  php_ artisan event:cache --no-interaction
) || die "A step failed in releases/$TS. The live release was not switched. The pre-deploy dump is $DUMP."

say "4. Switch current -> releases/$TS"
PREVIOUS="$(basename "$(readlink "$APP_DIR/current")")"
( cd "$APP_DIR" && ln -sfn "releases/$TS" current.new && mv -T current.new current )
echo "current now points to releases/$TS (was releases/$PREVIOUS)"

say "5. Smoke test"
APP_URL="$(env_value APP_URL)"
APP_URL="${APP_URL%/}"
HEALTH="$(curl -sS -m 20 -w '\nHTTP %{http_code}' "$APP_URL/healthz" 2>&1 || true)"
echo "$APP_URL/healthz -> $HEALTH"
case "$HEALTH" in
  *"HTTP 200"*) echo "Health check OK." ;;
  *) echo "Health check did NOT return 200. Check the site; to go back: bash deploy.sh --rollback" ;;
esac
echo "Now open the System page as super admin: it must show release $RELEASE_ID."
echo "If it still shows the previous release (stale PHP processes), stop and report; do not deploy again."

say "6. Keep the last $KEEP releases"
cd "$APP_DIR/releases"
CURRENT_NAME="$TS"
# Plain globbing (sorted): no process substitution, which needs /dev/fd and CageFS has none.
ALL=()
for ENTRY in *; do [ -d "$ENTRY" ] && ALL+=("$ENTRY"); done
COUNT="${#ALL[@]}"
if [ "$COUNT" -gt "$KEEP" ]; then
  for OLD in "${ALL[@]:0:$((COUNT - KEEP))}"; do
    [ "$OLD" = "$CURRENT_NAME" ] && continue
    case "$OLD" in */*|.|..|"") continue ;; esac
    rm -rf -- "$APP_DIR/releases/$OLD"
    echo "removed releases/$OLD"
  done
fi
echo "kept: $(ls -1 | sort | tr '\n' ' ')"

say "Deployed $RELEASE_ID"
