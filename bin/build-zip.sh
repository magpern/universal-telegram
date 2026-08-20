#!/usr/bin/env bash
# Builds the plugin's distributable ZIP. Runs inside the Docker php service
# via bin/docker/build-zip.sh; never invoked against a host PHP/Composer
# installation.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

VERSION="$(php -r '
    $contents = file_get_contents("universal-telegram.php");
    preg_match("/Version:\s*([0-9A-Za-z.\-]+)/", $contents, $m);
    echo $m[1];
')"

BUILD_DIR="/tmp/universal-telegram-build"
PLUGIN_DIR="${BUILD_DIR}/universal-telegram"
ZIP_NAME="universal-telegram-${VERSION}.zip"
DIST_DIR="${REPO_ROOT}/dist"

rm -rf "$BUILD_DIR"
mkdir -p "$PLUGIN_DIR"

# Copy the working tree (not a git-committed snapshot, so this also works
# when validating a work package before it is committed), then install
# production dependencies against the already-committed composer.lock so
# the resulting vendor/ tree is reproducible.
rsync -a --exclude='.git' --exclude='vendor' --exclude='dist' --exclude='node_modules' \
    "${REPO_ROOT}/" "${PLUGIN_DIR}/"

cd "$PLUGIN_DIR"
composer install --no-dev --no-interaction --optimize-autoloader --no-progress

if [ ! -f "vendor/woocommerce/action-scheduler/action-scheduler.php" ]; then
    echo "build-zip: vendor/woocommerce/action-scheduler/action-scheduler.php is missing from the packaged plugin" >&2
    exit 1
fi

rm -rf docker tests .github phpcs.xml.dist phpstan.neon.dist phpunit.xml.dist phpunit-integration.xml.dist

mkdir -p "$DIST_DIR"
cd "$BUILD_DIR"
rm -f "${DIST_DIR}/${ZIP_NAME}"
zip -r -q "${DIST_DIR}/${ZIP_NAME}" universal-telegram

echo "Built ${DIST_DIR}/${ZIP_NAME}"
