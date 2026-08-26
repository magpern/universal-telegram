#!/usr/bin/env bash
# Installs the REAL universal-support-chat plugin source (from the sibling
# checkout mounted at /support-chat by docker/docker-compose.interop.yml,
# on its feature/sc-m03-ut-interop-wp6 branch) into the WordPress core
# plugin directory prepared by tests/bin/install-wp.sh, and runs its own
# `composer install` so its own vendor/autoload.php exists. Mirrors the
# WooCommerce-zip mechanism in install-wp.sh, except the source is a local
# checkout, not a downloaded release, per the interop harness's own
# constraint (real merged plugin code, not a fixture).
#
# Usage: tests/bin/install-support-chat.sh
# Requires: WP_CORE_DIR exported (source /tmp/ut-wp-env.sh first).
set -euo pipefail

: "${WP_CORE_DIR:?WP_CORE_DIR is not set. Run tests/bin/install-wp.sh and source /tmp/ut-wp-env.sh first.}"

SUPPORT_CHAT_SRC="${SUPPORT_CHAT_SRC:-/support-chat}"
SUPPORT_CHAT_DEST="${WP_CORE_DIR}/wp-content/plugins/universal-support-chat"

if [ ! -f "${SUPPORT_CHAT_SRC}/universal-support-chat.php" ]; then
    echo "Support Chat source not found at ${SUPPORT_CHAT_SRC} (expected the sibling checkout mounted by docker-compose.interop.yml)." >&2
    exit 1
fi

if [ ! -d "${SUPPORT_CHAT_SRC}/vendor" ]; then
    echo "Installing Support Chat's own Composer dependencies..."
    composer install --no-interaction --no-progress --working-dir="${SUPPORT_CHAT_SRC}"
fi

mkdir -p "${WP_CORE_DIR}/wp-content/plugins"
rm -f "${SUPPORT_CHAT_DEST}"
ln -s "${SUPPORT_CHAT_SRC}" "${SUPPORT_CHAT_DEST}"

echo "Support Chat linked into ${SUPPORT_CHAT_DEST} -> ${SUPPORT_CHAT_SRC}"
