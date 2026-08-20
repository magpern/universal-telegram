#!/usr/bin/env bash
# Installs and exercises the plugin's own built distributable ZIP inside a
# real, bootable WordPress install driven entirely by WP-CLI. Invoked by
# bin/docker/test-package.sh inside the Docker php service. Unlike the
# PHPUnit integration suite, this proves the packaged ZIP itself —
# autoloading, vendor/ dependencies, real plugin activation hooks — works
# end to end, not only the dev source tree.
set -euo pipefail

WP_VERSION="${1:?WordPress version required, e.g. 7.1}"
WC_VERSION="${2:-}"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WP_DIR="/tmp/wp-package-test-${WP_VERSION}"
DB_NAME="wordpress_package_test"
DB_HOST="${WP_TESTS_DB_HOST:-db}"
DB_USER="${WP_TESTS_DB_USER:-root}"
DB_PASS="${WP_TESTS_DB_PASSWORD:-root}"
TABLE_PREFIX="wp_"

echo "== Building the distributable ZIP =="
bash "${REPO_ROOT}/bin/build-zip.sh"

VERSION="$(
	php -r '
		$contents = file_get_contents($argv[1] . "/universal-telegram.php");
		preg_match("/Version:\s*([0-9A-Za-z.\-]+)/", $contents, $m);
		echo $m[1];
	' "$REPO_ROOT"
)"
ZIP_PATH="${REPO_ROOT}/dist/universal-telegram-${VERSION}.zip"

if [ ! -f "$ZIP_PATH" ]; then
	echo "FAIL: expected ZIP not found at ${ZIP_PATH}" >&2
	exit 1
fi

echo "== Setting up a fresh WordPress ${WP_VERSION} install =="
rm -rf "$WP_DIR"
mkdir -p "$WP_DIR"

mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -e "DROP DATABASE IF EXISTS ${DB_NAME}; CREATE DATABASE ${DB_NAME};"

wp core download --version="$WP_VERSION" --path="$WP_DIR" --allow-root --force

wp config create \
	--path="$WP_DIR" \
	--dbname="$DB_NAME" \
	--dbuser="$DB_USER" \
	--dbpass="$DB_PASS" \
	--dbhost="$DB_HOST" \
	--dbprefix="$TABLE_PREFIX" \
	--allow-root \
	--extra-php <<'PHP'
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_DEBUG_LOG', true );
PHP

wp core install \
	--path="$WP_DIR" \
	--url="http://localhost" \
	--title="Package Test" \
	--admin_user=admin \
	--admin_password=admin \
	--admin_email=admin@example.com \
	--allow-root

echo "== Installing and activating the packaged plugin =="
wp plugin install "$ZIP_PATH" --activate --path="$WP_DIR" --allow-root

if [ -n "$WC_VERSION" ]; then
	echo "== Installing and activating WooCommerce ${WC_VERSION} =="
	wp plugin install woocommerce --version="$WC_VERSION" --activate --path="$WP_DIR" --allow-root
fi

# Schema provisioning is deliberately lazy: it runs on the plugin's own
# next `plugins_loaded`, not during activation itself (see
# Core\Lifecycle\Activator). `wp plugin install --activate` bootstraps
# WordPress before the plugin becomes active, so that same bootstrap's own
# `plugins_loaded` never includes it. A separate, later WP-CLI command is
# a fresh bootstrap that does.
wp eval 'true;' --path="$WP_DIR" --allow-root

audit_table_exists() {
	wp db query "SHOW TABLES LIKE '${TABLE_PREFIX}universal_telegram_audit_log'" --path="$WP_DIR" --allow-root --skip-column-names
}

bots_table_exists() {
	wp db query "SHOW TABLES LIKE '${TABLE_PREFIX}universal_telegram_bots'" --path="$WP_DIR" --allow-root --skip-column-names
}

echo "== Verifying activation created the plugin's own tables =="
if [ -z "$(audit_table_exists)" ]; then
	echo "FAIL: audit log table was not created on activation" >&2
	exit 1
fi
echo "OK: audit log table exists."

if [ -z "$(bots_table_exists)" ]; then
	echo "FAIL: bots table was not created on activation" >&2
	exit 1
fi
echo "OK: bots table exists."

echo "== Verifying the diagnostics page renders with the self-test control present =="
wp eval '
	$plugin = UniversalTelegram\Core\Plugin::instance();
	$page   = $plugin->diagnostics_page();

	ob_start();
	$page->render();
	$html = ob_get_clean();

	if ( false === strpos( $html, "Telegram Operations Hub" ) ) {
		fwrite( STDERR, "FAIL: diagnostics page did not render the expected heading\n" );
		exit( 1 );
	}
	if ( false === strpos( $html, "Diagnostic self-test" ) ) {
		fwrite( STDERR, "FAIL: self-test control was not rendered despite WP_DEBUG=true\n" );
		exit( 1 );
	}
	echo "OK: diagnostics page rendered with the self-test control present.\n";
' --path="$WP_DIR" --allow-root --user=admin

echo "== Verifying the self-test retry contract for fail_count=2 (fails twice, then succeeds) =="
wp eval '
	$plugin = UniversalTelegram\Core\Plugin::instance();
	$runner = $plugin->worker_runner();

	$succeeded = false;
	$failures  = 0;

	for ( $attempt = 1; $attempt <= 5; $attempt++ ) {
		$job = array(
			"job_id"   => "package-test-1",
			"job_type" => UniversalTelegram\Administration\Diagnostics\SelfTest::JOB_TYPE,
			"attempt"  => $attempt,
			"payload"  => array( "fail_count" => 2 ),
		);
		try {
			$runner->run( $job );
			$succeeded = true;
			break;
		} catch ( \Throwable $e ) {
			$failures++;
		}
	}

	if ( 2 !== $failures || ! $succeeded ) {
		fwrite( STDERR, "FAIL: expected 2 failures then a success, got failures={$failures}, succeeded=" . var_export( $succeeded, true ) . "\n" );
		exit( 1 );
	}
	echo "OK: fail_count=2 produced exactly 2 attempt failures then a success.\n";
' --path="$WP_DIR" --allow-root --user=admin

echo "== Verifying the self-test retry contract for fail_count=5 (fails every permitted attempt) =="
wp eval '
	$plugin = UniversalTelegram\Core\Plugin::instance();
	$runner = $plugin->worker_runner();

	$succeeded = false;
	$failures  = 0;

	for ( $attempt = 1; $attempt <= 5; $attempt++ ) {
		$job = array(
			"job_id"   => "package-test-2",
			"job_type" => UniversalTelegram\Administration\Diagnostics\SelfTest::JOB_TYPE,
			"attempt"  => $attempt,
			"payload"  => array( "fail_count" => 5 ),
		);
		try {
			$runner->run( $job );
			$succeeded = true;
			break;
		} catch ( \Throwable $e ) {
			$failures++;
		}
	}

	if ( 5 !== $failures || $succeeded ) {
		fwrite( STDERR, "FAIL: expected 5 failures and no success, got failures={$failures}, succeeded=" . var_export( $succeeded, true ) . "\n" );
		exit( 1 );
	}
	echo "OK: fail_count=5 failed all five permitted attempts, never succeeded.\n";
' --path="$WP_DIR" --allow-root --user=admin

echo "== Verifying no plaintext token appears in the bot management page's rendered output =="
wp eval '
	$plugin = UniversalTelegram\Core\Plugin::instance();
	$bots   = $plugin->bot_profile_repository();
	$known_synthetic_token = "123456789:AAH_package-test-known-synthetic-token";

	$bot = $bots->create( "Package Test Bot", $known_synthetic_token );

	if ( null === $bot ) {
		fwrite( STDERR, "FAIL: could not create a bot profile\n" );
		exit( 1 );
	}

	ob_start();
	$plugin->bot_management_page()->render();
	$html = ob_get_clean();

	if ( false !== strpos( $html, $known_synthetic_token ) ) {
		fwrite( STDERR, "FAIL: plaintext token appeared in the bot management page\n" );
		exit( 1 );
	}
	if ( false !== strpos( $html, $bot->token_ciphertext() ) ) {
		fwrite( STDERR, "FAIL: token ciphertext appeared in the bot management page\n" );
		exit( 1 );
	}
	echo "OK: no plaintext token or ciphertext appeared in the bot management page.\n";
' --path="$WP_DIR" --allow-root --user=admin

echo "== Verifying deactivation and reactivation preserve data =="
wp plugin deactivate universal-telegram --path="$WP_DIR" --allow-root
wp plugin activate universal-telegram --path="$WP_DIR" --allow-root

if [ -z "$(audit_table_exists)" ]; then
	echo "FAIL: audit log table lost across deactivate/reactivate" >&2
	exit 1
fi
echo "OK: deactivation and reactivation preserved the plugin's own data."

echo "== Verifying uninstall with default retention (false) keeps data =="
wp plugin deactivate universal-telegram --path="$WP_DIR" --allow-root
wp plugin uninstall universal-telegram --path="$WP_DIR" --allow-root

if [ -z "$(audit_table_exists)" ]; then
	echo "FAIL: default-retention uninstall removed data despite remove_data_on_uninstall defaulting to false" >&2
	exit 1
fi
if [ -z "$(bots_table_exists)" ]; then
	echo "FAIL: default-retention uninstall removed the bots table despite remove_data_on_uninstall defaulting to false" >&2
	exit 1
fi
echo "OK: default-retention uninstall kept the plugin's own data, including the bots table."

echo "== Reinstalling to verify uninstall with retention explicitly enabled removes data =="
wp plugin install "$ZIP_PATH" --activate --path="$WP_DIR" --allow-root
wp eval '
	update_option(
		UniversalTelegram\Core\Configuration\Settings::OPTION_NAME,
		array( "remove_data_on_uninstall" => true )
	);
	echo "OK: remove_data_on_uninstall set to true.\n";
' --path="$WP_DIR" --allow-root

wp plugin deactivate universal-telegram --path="$WP_DIR" --allow-root
wp plugin uninstall universal-telegram --path="$WP_DIR" --allow-root

if [ -n "$(audit_table_exists)" ]; then
	echo "FAIL: opt-in uninstall did not remove the plugin's own table" >&2
	exit 1
fi
if [ -n "$(bots_table_exists)" ]; then
	echo "FAIL: opt-in uninstall did not remove the bots table" >&2
	exit 1
fi
echo "OK: opt-in uninstall removed the plugin's own data, including all six Telegram tables."

echo "== PACKAGE TEST PASSED for WordPress ${WP_VERSION}${WC_VERSION:+, WooCommerce ${WC_VERSION}} =="
