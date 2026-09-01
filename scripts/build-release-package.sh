#!/usr/bin/env bash
#
# Deterministic release packager for universal-telegram.
#
# Usage:
#   scripts/build-release-package.sh [version]
#
# With no argument the version is taken from the plugin's canonical source
# (see "Release process" in the repository docs). An explicit argument (with
# or without a leading "v") must equal that canonical version or the build
# fails. This script never rewrites version files.
#
# Output (dist/, gitignored, never committed):
#   universal-telegram-<version>.zip
#   universal-telegram-<version>.zip.sha256
#
# Strict shell options; safe to run repeatedly; non-zero exit on any error.
#
set -Eeuo pipefail

# ===== per-repo configuration ==============================================
SLUG="universal-telegram"
MAIN_FILE="universal-telegram.php"
VERSION_CONST="UNIVERSAL_TELEGRAM_VERSION"
INCLUDE=("$MAIN_FILE" universal-telegram-functions.php uninstall.php src readme.txt composer.json README.md LICENSE)
HAS_README_TXT="1"        # validate readme.txt Stable tag + changelog
HAS_CHANGELOG_MD="0"    # validate CHANGELOG.md "## [<version>]" section
REQUIRE_VENDOR_FILE="woocommerce/action-scheduler/action-scheduler.php"  # path under vendor/ that must exist post-install
# ==========================================================================

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

log() { printf '==> %s\n' "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

for tool in composer zip unzip sha256sum rsync sed grep; do
	command -v "$tool" >/dev/null 2>&1 || die "required tool not found on PATH: $tool"
done
[ -f "$MAIN_FILE" ] || die "main plugin file missing: $MAIN_FILE"

header_version="$(sed -nE 's/^[[:space:]]*\*?[[:space:]]*Version:[[:space:]]*([0-9A-Za-z.+-]+).*/\1/p' "$MAIN_FILE" | head -n1)"
const_version="$(sed -nE "s/.*${VERSION_CONST}',[[:space:]]*'([^']+)'.*/\1/p" "$MAIN_FILE" | head -n1)"
[ -n "$header_version" ] || die "could not parse plugin header 'Version:' from $MAIN_FILE"
[ -n "$const_version" ] || die "could not parse ${VERSION_CONST} from $MAIN_FILE"
[ "$header_version" = "$const_version" ] \
	|| die "version mismatch: header=$header_version ${VERSION_CONST}=$const_version"

if [ "$HAS_README_TXT" = "1" ]; then
	[ -f readme.txt ] || die "readme.txt expected but missing"
	stable="$(sed -nE 's/^Stable tag:[[:space:]]*([0-9A-Za-z.+-]+).*/\1/p' readme.txt | head -n1)"
	[ -n "$stable" ] || die "readme.txt has no 'Stable tag:' line"
	[ "$stable" = "$header_version" ] \
		|| die "readme.txt Stable tag ($stable) != plugin version ($header_version)"
	grep -qE "^= ${header_version//./\\.} =" readme.txt \
		|| die "readme.txt has no changelog section '= ${header_version} ='"
fi

if [ "$HAS_CHANGELOG_MD" = "1" ]; then
	[ -f CHANGELOG.md ] || die "CHANGELOG.md expected but missing"
	grep -qE "^## \[${header_version//./\\.}\]" CHANGELOG.md \
		|| die "CHANGELOG.md has no '## [${header_version}]' section"
fi

VERSION="$header_version"
if [ -n "${1:-}" ]; then
	want="${1#v}"
	[ "$want" = "$VERSION" ] \
		|| die "requested version '$1' does not match canonical version '$VERSION'"
fi

DIST_DIR="$ROOT/dist"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/${SLUG}-pkg.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT
PKG="$WORK/$SLUG"
mkdir -p "$PKG" "$DIST_DIR"

log "Staging package tree for $SLUG $VERSION"
for path in "${INCLUDE[@]}"; do
	[ -e "$path" ] || die "configured INCLUDE path is missing: $path"
	rsync -a --relative "$path" "$PKG/"
done
[ -f "$PKG/composer.json" ] || die "composer.json must be listed in INCLUDE"
[ -f composer.lock ] && cp composer.lock "$PKG/composer.lock"

log "Installing production (no-dev) dependencies"
(
	cd "$PKG"
	composer install --no-dev --no-interaction --no-progress \
		--classmap-authoritative --prefer-dist 2>&1 | sed 's/^/    /'
)
rm -f "$PKG/composer.lock"
[ -f "$PKG/vendor/autoload.php" ] || die "composer did not produce vendor/autoload.php"
if [ -n "$REQUIRE_VENDOR_FILE" ]; then
	[ -f "$PKG/vendor/$REQUIRE_VENDOR_FILE" ] \
		|| die "expected bundled dependency missing: vendor/$REQUIRE_VENDOR_FILE"
fi

ZIP="$DIST_DIR/${SLUG}-${VERSION}.zip"
SUM="${ZIP}.sha256"
rm -f "$ZIP" "$SUM"
log "Creating $ZIP"
( cd "$WORK" && find . -name '.DS_Store' -delete && zip -qr "$ZIP" "$SLUG" )
( cd "$DIST_DIR" && sha256sum "$(basename "$ZIP")" > "$(basename "$SUM")" )

log "Verifying package"
names="$(unzip -Z1 "$ZIP")"
[ -n "$names" ] || die "archive is empty"
tops="$(printf '%s\n' "$names" | sed 's#/.*##' | sort -u)"
[ "$tops" = "$SLUG" ] || die "archive top-level must be exactly '$SLUG', got: $(echo $tops)"
if printf '%s\n' "$names" | grep -qE "^${SLUG}/(\.git|\.github|\.claude|\.cursor|tests|docs|docker|bin|scripts|node_modules)/"; then
	printf '%s\n' "$names" | grep -E "^${SLUG}/(\.git|\.github|\.claude|\.cursor|tests|docs|docker|bin|scripts|node_modules)/" >&2
	die "forbidden development directory in package"
fi
if printf '%s\n' "$names" | grep -qE "^${SLUG}/(\.gitignore|\.gitattributes|\.editorconfig|composer\.lock|phpcs\.xml\.dist|phpstan\.neon\.dist|phpstan-bootstrap\.php|phpunit[^/]*\.xml\.dist|\.phpunit\.result\.cache|package\.json|package-lock\.json|CONTRIBUTING\.md)$"; then
	printf '%s\n' "$names" | grep -E "^${SLUG}/(\.gitignore|\.gitattributes|\.editorconfig|composer\.lock|phpcs\.xml\.dist|phpstan\.neon\.dist|phpstan-bootstrap\.php|phpunit[^/]*\.xml\.dist|\.phpunit\.result\.cache|package\.json|package-lock\.json|CONTRIBUTING\.md)$" >&2
	die "forbidden development file in package"
fi
printf '%s\n' "$names" | grep -qE "\.(zip|sha256)$" && die "nested archive/checksum in package"
printf '%s\n' "$names" | grep -qx "${SLUG}/${MAIN_FILE}" || die "main plugin file missing from package"
printf '%s\n' "$names" | grep -qx "${SLUG}/vendor/autoload.php" || die "vendor/autoload.php missing from package"
pkg_main="$(unzip -p "$ZIP" "${SLUG}/${MAIN_FILE}")"
pkg_ver="$(printf '%s\n' "$pkg_main" | sed -nE 's/^[[:space:]]*\*?[[:space:]]*Version:[[:space:]]*([0-9A-Za-z.+-]+).*/\1/p' | head -n1)"
[ "$pkg_ver" = "$VERSION" ] || die "packaged plugin Version ($pkg_ver) != $VERSION"

log "Done"
echo "  $ZIP"
echo "  $SUM"
cat "$SUM"
echo
echo "  $(printf '%s\n' "$names" | wc -l) entries; sole top-level '$SLUG'"
