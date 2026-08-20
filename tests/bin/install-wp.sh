#!/usr/bin/env bash
# Fetches a specific WordPress core release together with the official
# WordPress core test-suite scaffold matching that exact same version, and
# optionally a pinned WooCommerce release, into version-specific directories.
# Never relies on a single Composer-resolved test-scaffold package to serve
# more than one WordPress-core version.
#
# Usage: tests/bin/install-wp.sh <wp-version> [wc-version]
#
# On success, writes /tmp/ut-wp-env.sh exporting WP_TESTS_DIR and
# WP_CORE_DIR (and UT_TEST_WC_ACTIVE=1 if a WooCommerce version was given)
# for the caller to source before running the integration test suite.
set -euo pipefail

WP_VERSION="${1:?WordPress version required, e.g. 6.9}"
WC_VERSION="${2:-}"

WP_CORE_DIR="/tmp/wordpress-${WP_VERSION}"
WP_TESTS_DIR="/tmp/wordpress-tests-lib-${WP_VERSION}"
ENV_FILE="/tmp/ut-wp-env.sh"

DB_HOST="${WP_TESTS_DB_HOST:-db}"
DB_USER="${WP_TESTS_DB_USER:-root}"
DB_PASS="${WP_TESTS_DB_PASSWORD:-root}"
DB_NAME="${WP_TESTS_DB_NAME:-wordpress_test}"

if [ ! -f "$WP_CORE_DIR/wp-load.php" ]; then
    echo "Downloading WordPress ${WP_VERSION} core..."
    rm -rf "$WP_CORE_DIR"
    wp core download --version="$WP_VERSION" --path="$WP_CORE_DIR" --allow-root --force
fi

if [ ! -d "$WP_TESTS_DIR/includes" ]; then
    echo "Exporting WordPress ${WP_VERSION} test-suite scaffold..."
    rm -rf "$WP_TESTS_DIR"
    mkdir -p "$WP_TESTS_DIR"
    svn export --quiet "https://develop.svn.wordpress.org/tags/${WP_VERSION}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
    svn export --quiet "https://develop.svn.wordpress.org/tags/${WP_VERSION}/tests/phpunit/data/" "$WP_TESTS_DIR/data"
    curl -sf -o "$WP_TESTS_DIR/wp-tests-config.php" "https://develop.svn.wordpress.org/tags/${WP_VERSION}/wp-tests-config-sample.php"

    grep -v "ABSPATH" "$WP_TESTS_DIR/wp-tests-config.php" > "$WP_TESTS_DIR/wp-tests-config.php.tmp"
    mv "$WP_TESTS_DIR/wp-tests-config.php.tmp" "$WP_TESTS_DIR/wp-tests-config.php"

    sed -i \
        -e "s/youremptytestdbnamehere/${DB_NAME}/" \
        -e "s/yourusernamehere/${DB_USER}/" \
        -e "s/yourpasswordhere/${DB_PASS}/" \
        -e "s/'DB_HOST', 'localhost'/'DB_HOST', '${DB_HOST}'/" \
        "$WP_TESTS_DIR/wp-tests-config.php"

    {
        echo ""
        echo "define( 'ABSPATH', '${WP_CORE_DIR}/' );"
    } >> "$WP_TESTS_DIR/wp-tests-config.php"
fi

if [ -n "$WC_VERSION" ]; then
    if [ ! -f "$WP_CORE_DIR/wp-content/plugins/woocommerce/woocommerce.php" ]; then
        echo "Downloading WooCommerce ${WC_VERSION}..."
        # WP-CLI's `wp plugin install` requires a fully bootstrapped
        # WordPress install (wp-config.php, a live DB connection), which
        # this script deliberately does not create — the WP core test
        # framework provisions its own test database itself. Fetching the
        # plugin ZIP directly from wordpress.org and extracting it needs
        # no such bootstrap.
        mkdir -p "$WP_CORE_DIR/wp-content/plugins"
        curl -sf -o /tmp/woocommerce.zip "https://downloads.wordpress.org/plugin/woocommerce.${WC_VERSION}.zip"
        unzip -q -o /tmp/woocommerce.zip -d "$WP_CORE_DIR/wp-content/plugins"
        rm -f /tmp/woocommerce.zip
    fi
fi

{
    echo "export WP_TESTS_DIR='${WP_TESTS_DIR}'"
    echo "export WP_CORE_DIR='${WP_CORE_DIR}'"
    if [ -n "$WC_VERSION" ]; then
        echo "export UT_TEST_WC_ACTIVE=1"
        echo "export UT_TEST_WC_VERSION='${WC_VERSION}'"
    fi
} > "$ENV_FILE"

echo "WordPress ${WP_VERSION} test environment ready: WP_CORE_DIR=${WP_CORE_DIR} WP_TESTS_DIR=${WP_TESTS_DIR}"
