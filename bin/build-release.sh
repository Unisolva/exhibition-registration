#!/usr/bin/env bash
# Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.
#
# Build a release archive off the server (work order §11). Run from the repository root on a
# machine with the same PHP minor version as production and Composer:
#
#   bin/build-release.sh            the commit must be tagged v<version> (version from config/app.php)
#   bin/build-release.sh --dev      allow an untagged or modified tree (for rehearsals only)
#
# Output: build/release-<version>[-dev-<commit>].tgz and its .sha256. The archive holds the code
# and vendor/, never .env, storage/, tests/ or node_modules/. A RELEASE file inside records the
# version, commit, tag and build time; bin/deploy.sh reads it.
set -euo pipefail

DEV=0
[ "${1:-}" = "--dev" ] && DEV=1

cd "$(git rev-parse --show-toplevel)"

# The version of the commit being archived, not of the working tree.
VERSION="$(git show HEAD:config/app.php | sed -n "s/^[[:space:]]*'version' => '\([^']*\)',.*/\1/p" | head -1)"
[ -n "$VERSION" ] || { echo "Could not read the version from config/app.php" >&2; exit 1; }
COMMIT="$(git rev-parse --short=12 HEAD)"
TAG="v$VERSION"
TAGGED=no
if git rev-parse -q --verify "refs/tags/$TAG" >/dev/null && [ "$(git rev-list -n 1 "$TAG")" = "$(git rev-parse HEAD)" ]; then
  TAGGED=yes
fi
DIRTY=no
[ -z "$(git status --porcelain --untracked-files=no)" ] || DIRTY=yes

if [ "$DEV" -eq 0 ]; then
  [ "$TAGGED" = yes ] || { echo "HEAD is not tagged $TAG. Tag the release commit, or pass --dev for a rehearsal build." >&2; exit 1; }
  [ "$DIRTY" = no ] || { echo "The working tree has uncommitted changes. Commit them, or pass --dev." >&2; exit 1; }
fi

NAME="release-$VERSION"
[ "$DEV" -eq 1 ] && NAME="release-$VERSION-dev-$COMMIT"
BUILD_DIR="$(pwd)/build"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

echo "Exporting $COMMIT (tag $TAG: $TAGGED) ..."
# git archive honours export-ignore in .gitattributes (tests/, .github/).
git archive --format=tar HEAD | tar -x -C "$WORK"

echo "Installing production dependencies ..."
( cd "$WORK" && composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet )
( cd "$WORK" && php artisan package:discover --no-interaction >/dev/null )

# Never in a release: storage (linked to shared/ on the server), environment files, tests, Node.
rm -rf "$WORK/storage" "$WORK/tests" "$WORK/node_modules" "$WORK/build"
rm -f "$WORK"/.env "$WORK"/.env.* "$WORK"/bootstrap/cache/config.php "$WORK"/bootstrap/cache/routes-*.php
if find "$WORK" -path "$WORK/vendor" -prune -o \( -name '.env' -o -name '*.sql' -o -name '*.sql.gz' -o -name '*.inv' \) -print | grep -q .; then
  echo "Refusing: a private file is in the export." >&2; exit 1
fi

PHP_PLATFORM="$(php -r 'echo json_decode(file_get_contents("composer.json"), true)["config"]["platform"]["php"] ?? "";' 2>/dev/null || true)"
cat > "$WORK/RELEASE" <<EOF
version=$VERSION
id=$VERSION+$COMMIT
commit=$COMMIT
tagged=$TAGGED
dirty=$DIRTY
php_platform=$PHP_PLATFORM
built_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)
EOF

mkdir -p "$BUILD_DIR"
tar -czf "$BUILD_DIR/$NAME.tgz" -C "$WORK" .
( cd "$BUILD_DIR" && sha256sum "$NAME.tgz" > "$NAME.tgz.sha256" )

echo "Built build/$NAME.tgz ($(du -h "$BUILD_DIR/$NAME.tgz" | cut -f1))"
cat "$BUILD_DIR/$NAME.tgz.sha256"
