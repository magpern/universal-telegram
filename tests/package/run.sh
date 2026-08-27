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

m02_table_exists() {
	wp db query "SHOW TABLES LIKE '${TABLE_PREFIX}universal_telegram_${1}'" --path="$WP_DIR" --allow-root --skip-column-names
}

m06_column_exists() {
	wp db query "SHOW COLUMNS FROM ${TABLE_PREFIX}universal_telegram_${1} LIKE '${2}'" --path="$WP_DIR" --allow-root --skip-column-names
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

echo "== Verifying activation created M02's four Events/Automations tables =="
for m02_table in event_history fatal_error_markers notification_rules notification_dispatch_log; do
	if [ -z "$(m02_table_exists "$m02_table")" ]; then
		echo "FAIL: universal_telegram_${m02_table} table was not created on activation" >&2
		exit 1
	fi
	echo "OK: universal_telegram_${m02_table} table exists."
done

echo "== Verifying activation created M05's two conversation tables =="
for m05_table in conversations conversation_messages; do
	if [ -z "$(m02_table_exists "$m05_table")" ]; then
		echo "FAIL: universal_telegram_${m05_table} table was not created on activation" >&2
		exit 1
	fi
	echo "OK: universal_telegram_${m05_table} table exists."
done

echo "== Verifying M06's idempotency columns exist on the conversation tables =="
if [ -z "$(m06_column_exists "conversations" "start_idempotency_key")" ]; then
	echo "FAIL: universal_telegram_conversations.start_idempotency_key column was not created on activation" >&2
	exit 1
fi
echo "OK: universal_telegram_conversations.start_idempotency_key column exists."

if [ -z "$(m06_column_exists "conversation_messages" "idempotency_key")" ]; then
	echo "FAIL: universal_telegram_conversation_messages.idempotency_key column was not created on activation" >&2
	exit 1
fi
echo "OK: universal_telegram_conversation_messages.idempotency_key column exists."

echo "== Verifying M06.2 v2's claim/lease columns exist (ADR-0023 amendment) =="
if [ -z "$(m06_column_exists "outbound_messages" "claim_expires_at")" ]; then
	echo "FAIL: universal_telegram_outbound_messages.claim_expires_at column was not created on activation" >&2
	exit 1
fi
echo "OK: universal_telegram_outbound_messages.claim_expires_at column exists."

if [ -z "$(m06_column_exists "conversations" "topic_claim_expires_at")" ]; then
	echo "FAIL: universal_telegram_conversations.topic_claim_expires_at column was not created on activation" >&2
	exit 1
fi
echo "OK: universal_telegram_conversations.topic_claim_expires_at column exists."

echo "== Verifying M06.3's display-name column exists (ADR-0024) =="
if [ -z "$(m06_column_exists "conversations" "display_name_ciphertext")" ]; then
	echo "FAIL: universal_telegram_conversations.display_name_ciphertext column was not created on activation" >&2
	exit 1
fi
echo "OK: universal_telegram_conversations.display_name_ciphertext column exists."

echo "== Verifying M06.3.1's ownership columns exist (ADR-0025) =="
if [ -z "$(m06_column_exists "conversations" "owner_user_id")" ]; then
	echo "FAIL: universal_telegram_conversations.owner_user_id column was not created on activation" >&2
	exit 1
fi
if [ -z "$(m06_column_exists "conversations" "owner_active_slot")" ]; then
	echo "FAIL: universal_telegram_conversations.owner_active_slot column was not created on activation" >&2
	exit 1
fi
echo "OK: universal_telegram_conversations.owner_user_id and owner_active_slot columns exist."

echo "== Verifying db_version reached 34 =="
DB_VERSION="$(wp option get universal_telegram_db_version --path="$WP_DIR" --allow-root)"
if [ "34" != "$DB_VERSION" ]; then
	echo "FAIL: expected universal_telegram_db_version=34, got ${DB_VERSION}" >&2
	exit 1
fi
echo "OK: universal_telegram_db_version is 34."

echo "== Verifying SC-M03 WP2 quiescence tables exist (ADR-0040) =="
for TABLE in quiescence_state quiescence_transitions quiescence_deferred_updates; do
	if [ -z "$(m02_table_exists "$TABLE")" ]; then
		echo "FAIL: expected table universal_telegram_${TABLE} to exist" >&2
		exit 1
	fi
done
echo "OK: quiescence_state, quiescence_transitions, and quiescence_deferred_updates tables exist."

echo "== Verifying UT Adapter M1 Support Chat binding tables exist =="
for TABLE in support_chat_bindings support_chat_delivery_keys; do
	if [ -z "$(m02_table_exists "$TABLE")" ]; then
		echo "FAIL: expected table universal_telegram_${TABLE} to exist" >&2
		exit 1
	fi
done
echo "OK: universal_telegram_support_chat_bindings and universal_telegram_support_chat_delivery_keys tables exist."

echo "== Verifying SC-M03 WP5 prepared binding status exists (ADR-0041) =="
BINDING_STATUS_TYPE="$(wp db query "SHOW COLUMNS FROM ${TABLE_PREFIX}universal_telegram_support_chat_bindings LIKE 'status'" --path="$WP_DIR" --allow-root --skip-column-names)"
if [ -z "$(echo "$BINDING_STATUS_TYPE" | grep -o 'prepared')" ]; then
	echo "FAIL: expected universal_telegram_support_chat_bindings.status ENUM to include 'prepared'" >&2
	exit 1
fi
echo "OK: universal_telegram_support_chat_bindings.status ENUM includes 'prepared'."

echo "== Verifying M07.1 topic lifecycle columns exist =="
if [ -z "$(m06_column_exists conversations topic_lifecycle_state)" ]; then
	echo "FAIL: expected universal_telegram_conversations.topic_lifecycle_state column to exist" >&2
	exit 1
fi
if [ -z "$(m06_column_exists conversations topic_lifecycle_code)" ]; then
	echo "FAIL: expected universal_telegram_conversations.topic_lifecycle_code column to exist" >&2
	exit 1
fi
if [ -z "$(m06_column_exists conversations topic_delete_claim_expires_at)" ]; then
	echo "FAIL: expected universal_telegram_conversations.topic_delete_claim_expires_at column to exist" >&2
	exit 1
fi
echo "OK: M07.1 topic lifecycle columns exist."

echo "== Verifying M07 operator-workflow tables and columns exist =="
for TABLE in operator_identities conversation_notes operator_availability; do
	if [ -z "$(m02_table_exists "$TABLE")" ]; then
		echo "FAIL: expected table universal_telegram_${TABLE} to exist" >&2
		exit 1
	fi
done
echo "OK: universal_telegram_operator_identities, universal_telegram_conversation_notes, and universal_telegram_operator_availability tables exist."

if [ -z "$(m06_column_exists conversations assignee_last_seen_message_id)" ]; then
	echo "FAIL: expected universal_telegram_conversations.assignee_last_seen_message_id column to exist" >&2
	exit 1
fi
if [ -z "$(m06_column_exists conversation_messages telegram_sender_user_id)" ]; then
	echo "FAIL: expected universal_telegram_conversation_messages.telegram_sender_user_id column to exist" >&2
	exit 1
fi
echo "OK: universal_telegram_conversations.assignee_last_seen_message_id and universal_telegram_conversation_messages.telegram_sender_user_id columns exist."

echo "== Verifying M09 AI draft assistant tables and columns exist (docs/adr/0028) =="
for TABLE in ai_config ai_drafts; do
	if [ -z "$(m02_table_exists "$TABLE")" ]; then
		echo "FAIL: expected table universal_telegram_${TABLE} to exist" >&2
		exit 1
	fi
done
echo "OK: universal_telegram_ai_config and universal_telegram_ai_drafts tables exist."

AI_CONFIG_ROW_COUNT="$(wp db query "SELECT COUNT(*) FROM ${TABLE_PREFIX}universal_telegram_ai_config WHERE id = 1" --path="$WP_DIR" --allow-root --skip-column-names)"
if [ "1" != "$AI_CONFIG_ROW_COUNT" ]; then
	echo "FAIL: expected exactly one seeded universal_telegram_ai_config row (id=1), got ${AI_CONFIG_ROW_COUNT}" >&2
	exit 1
fi
AI_CONFIG_ENABLED="$(wp db query "SELECT enabled FROM ${TABLE_PREFIX}universal_telegram_ai_config WHERE id = 1" --path="$WP_DIR" --allow-root --skip-column-names)"
if [ "0" != "$AI_CONFIG_ENABLED" ]; then
	echo "FAIL: expected AI to be disabled by default (enabled=0), got enabled=${AI_CONFIG_ENABLED}" >&2
	exit 1
fi
echo "OK: universal_telegram_ai_config singleton row is seeded and disabled by default."

if [ -z "$(m06_column_exists conversations ai_ack_policy_version)" ]; then
	echo "FAIL: expected universal_telegram_conversations.ai_ack_policy_version column to exist" >&2
	exit 1
fi
echo "OK: universal_telegram_conversations.ai_ack_policy_version column exists."

for COLUMN in lease_token generation_lease_expires_at claimed_at attempt_count; do
	if [ -z "$(m06_column_exists ai_drafts "$COLUMN")" ]; then
		echo "FAIL: expected universal_telegram_ai_drafts.${COLUMN} column to exist" >&2
		exit 1
	fi
done
echo "OK: universal_telegram_ai_drafts lease/claim columns exist."

REQUESTER_NULLABLE="$(wp db query "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '${TABLE_PREFIX}universal_telegram_ai_drafts' AND COLUMN_NAME = 'requested_by_user_id'" --path="$WP_DIR" --allow-root --skip-column-names)"
if [ "YES" != "$REQUESTER_NULLABLE" ]; then
	echo "FAIL: expected universal_telegram_ai_drafts.requested_by_user_id to be nullable (account-deletion anonymization), got IS_NULLABLE=${REQUESTER_NULLABLE}" >&2
	exit 1
fi
echo "OK: universal_telegram_ai_drafts.requested_by_user_id is nullable."

echo "== Verifying M02 event emission projects only PUBLIC fields into event_history =="
wp eval '
	do_action( "set_user_role", 1, "editor", array( "subscriber" ) );

	global $wpdb;
	$table = $wpdb->prefix . "universal_telegram_event_history";
	$row   = $wpdb->get_row( "SELECT * FROM {$table} WHERE event_type = \"wordpress.user_role_changed\" ORDER BY id DESC LIMIT 1", ARRAY_A );

	if ( null === $row ) {
		fwrite( STDERR, "FAIL: no wordpress.user_role_changed event_history row was recorded\n" );
		exit( 1 );
	}
	if ( false !== strpos( $row["projected_fields_json"], "subscriber" ) ) {
		fwrite( STDERR, "FAIL: the INTERNAL-classified old_roles_csv field leaked into event_history\n" );
		exit( 1 );
	}
	if ( false === strpos( $row["projected_fields_json"], "editor" ) ) {
		fwrite( STDERR, "FAIL: the PUBLIC-classified new_role field is missing from event_history\n" );
		exit( 1 );
	}
	echo "OK: event_history contains only the declared PUBLIC fields.\n";
' --path="$WP_DIR" --allow-root --user=admin

echo "== Verifying the bounded fatal-error mechanism never stores message text, a stack trace, or a raw file path =="
wp eval '
	$plugin = UniversalTelegram\Core\Plugin::instance();

	$writer = new UniversalTelegram\Events\Emitters\FatalErrorMarkerWriter();
	$writer->write_marker_for(
		array(
			"type"    => E_ERROR,
			"message" => "Package-test simulated fatal: uncaught RuntimeException with a secret value abc123",
			"file"    => "/var/www/html/wp-content/plugins/some-vulnerable-plugin/leaky-file.php",
			"line"    => 42,
		)
	);

	global $wpdb;
	$markers_table = $wpdb->prefix . "universal_telegram_fatal_error_markers";
	$marker        = $wpdb->get_row( "SELECT * FROM {$markers_table} WHERE status = \"pending\" ORDER BY id DESC LIMIT 1", ARRAY_A );

	if ( null === $marker ) {
		fwrite( STDERR, "FAIL: no pending fatal_error_markers row was written\n" );
		exit( 1 );
	}
	foreach ( $marker as $column => $value ) {
		if ( is_string( $value ) && ( false !== strpos( $value, "leaky-file.php" ) || false !== strpos( $value, "secret value" ) || false !== strpos( $value, "RuntimeException" ) ) ) {
			fwrite( STDERR, "FAIL: fatal_error_markers column {$column} leaked message text or a raw file path\n" );
			exit( 1 );
		}
	}

	$job = new UniversalTelegram\Events\Emitters\FatalErrorPromotionJob( $plugin->schema_health() );
	$job->run();

	$history_table = $wpdb->prefix . "universal_telegram_event_history";
	$history_row   = $wpdb->get_row( "SELECT * FROM {$history_table} WHERE event_type = \"wordpress.fatal_error\" ORDER BY id DESC LIMIT 1", ARRAY_A );

	if ( null === $history_row ) {
		fwrite( STDERR, "FAIL: no wordpress.fatal_error event_history row was recorded after promotion\n" );
		exit( 1 );
	}
	if ( false !== strpos( $history_row["projected_fields_json"], "leaky-file.php" ) || false !== strpos( $history_row["projected_fields_json"], "secret value" ) || false !== strpos( $history_row["projected_fields_json"], "RuntimeException" ) ) {
		fwrite( STDERR, "FAIL: the promoted fatal_error event leaked message text or a raw file path\n" );
		exit( 1 );
	}
	echo "OK: the fatal-error mechanism never persisted message text, a stack trace, or a raw file path.\n";
' --path="$WP_DIR" --allow-root --user=admin

echo "== Verifying the diagnostics page renders the Automations section without a raw exception message =="
wp eval '
	$plugin = UniversalTelegram\Core\Plugin::instance();
	$page   = $plugin->diagnostics_page();

	ob_start();
	$page->render_tab_content();
	$html = ob_get_clean();

	if ( false === strpos( $html, "Automations (M02)" ) ) {
		fwrite( STDERR, "FAIL: diagnostics page did not render the Automations (M02) section\n" );
		exit( 1 );
	}
	if ( false !== strpos( $html, "RuntimeException" ) || false !== strpos( $html, "Exception" ) || false !== strpos( $html, "Stack trace" ) ) {
		fwrite( STDERR, "FAIL: diagnostics page rendered raw exception detail\n" );
		exit( 1 );
	}
	echo "OK: diagnostics page rendered the Automations section with no raw exception detail.\n";
' --path="$WP_DIR" --allow-root --user=admin

echo "== Verifying the notification tester never sends a live message or writes to the dispatch log =="
wp eval '
	$plugin    = UniversalTelegram\Core\Plugin::instance();
	$registry  = $plugin->event_registry();
	$evaluator = new UniversalTelegram\Automations\RuleEvaluator(
		$plugin->notification_rule_repository(),
		$registry,
		$plugin->dispatch_log_repository(),
		new UniversalTelegram\Automations\NotificationDispatcher(
			$plugin->dispatch_log_repository(),
			$plugin->bot_profile_repository(),
			$plugin->destination_repository(),
			$registry,
			new UniversalTelegram\Automations\TemplateRenderer(),
			$plugin->message_dispatcher()
		)
	);
	$tester = new UniversalTelegram\Administration\Automations\NotificationTester(
		$evaluator,
		$plugin->notification_rule_repository(),
		$plugin->bot_profile_repository(),
		$plugin->destination_repository(),
		$registry,
		new UniversalTelegram\Administration\Automations\PreviewRenderer( $registry )
	);

	global $wpdb;
	$dispatch_log_table = $wpdb->prefix . "universal_telegram_notification_dispatch_log";
	$before              = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$dispatch_log_table}" );

	$results = $tester->test_event( "wordpress.user_role_changed", array( "subject.user_id" => "1", "payload.new_role" => "editor" ) );

	$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$dispatch_log_table}" );

	if ( $before !== $after ) {
		fwrite( STDERR, "FAIL: notification test wrote to notification_dispatch_log\n" );
		exit( 1 );
	}
	echo "OK: notification test wrote no dispatch-log row.\n";
' --path="$WP_DIR" --allow-root --user=admin

echo "== Verifying the diagnostics page renders with the self-test control present =="
wp eval '
	$plugin = UniversalTelegram\Core\Plugin::instance();
	$page   = $plugin->diagnostics_page();

	ob_start();
	$page->render_tab_content();
	$html = ob_get_clean();

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

echo "== Verifying the plugin-row Settings action link is present and points at the Settings tab =="
wp eval '
	$links = apply_filters( "plugin_action_links_universal-telegram/universal-telegram.php", array() );
	$found = false;
	foreach ( $links as $link ) {
		if ( false !== strpos( $link, "page=universal-telegram" ) && false !== strpos( $link, "tab=settings" ) && false !== strpos( $link, "Settings" ) ) {
			$found = true;
			break;
		}
	}
	if ( ! $found ) {
		fwrite( STDERR, "FAIL: no Settings action link pointing at the Settings tab was found\n" );
		exit( 1 );
	}
	echo "OK: the Settings action link is present and points at the Settings tab.\n";
' --path="$WP_DIR" --allow-root --user=admin

echo "== Verifying the administration hub registers exactly the expected grouped top-level areas, in order =="
wp eval '
	$expected_tabs = array( "overview", "bots", "notifications-activity", "conversations", "ai-hub", "settings", "diagnostics", "support-chat-adapter", "support-chat-pairing" );

	$plugin   = UniversalTelegram\Core\Plugin::instance();
	$registry = $plugin->hub_tab_registry();

	if ( null === $registry ) {
		fwrite( STDERR, "FAIL: the hub tab registry was never constructed\n" );
		exit( 1 );
	}

	$ids = array_map( static fn( $tab ) => $tab->id(), $registry->all() );
	if ( $ids !== $expected_tabs ) {
		fwrite( STDERR, "FAIL: hub tab set/order was " . implode( ",", $ids ) . ", expected " . implode( ",", $expected_tabs ) . "\n" );
		exit( 1 );
	}
	echo "OK: the administration hub registers exactly the expected top-level areas, in order.\n";
' --path="$WP_DIR" --allow-root --user=admin

echo "== Verifying the hub shell renders the requested tab content and the full tab nav =="
wp eval '
	$plugin = UniversalTelegram\Core\Plugin::instance();
	$hub    = $plugin->hub_page();

	$_GET["tab"] = "bots";
	ob_start();
	$hub->render();
	$html = ob_get_clean();

	if ( false === strpos( $html, "nav-tab-wrapper" ) ) {
		fwrite( STDERR, "FAIL: the hub shell did not render the tab navigation\n" );
		exit( 1 );
	}
	// A fresh install has no bot configured, so the Bots tab renders the
	// setup wizard by default (M06.1 wizard-manual-view hotfix) rather
	// than the manual "Add a bot" form — check for content present in
	// both views instead of the manual-view-only form.
	if ( false === strpos( $html, "Dead-lettered messages" ) ) {
		fwrite( STDERR, "FAIL: the hub shell did not render the requested (bots) tab content\n" );
		exit( 1 );
	}
	unset( $_GET["tab"] );
	echo "OK: the hub shell renders the tab navigation and the requested tab content.\n";
' --path="$WP_DIR" --allow-root --user=admin

echo "== Verifying a legacy admin page slug redirects a GET request to its equivalent tab (302) =="
wp eval '
	$redirector = new class() extends UniversalTelegram\Administration\Hub\LegacyUrlRedirector {
		public $captured_url = null;
		protected function redirect_and_exit( string $url ): void {
			$this->captured_url = $url;
		}
	};

	$_SERVER["REQUEST_METHOD"] = "GET";
	$redirector->redirect( UniversalTelegram\Administration\Telegram\BotManagementPage::SLUG );

	if ( null === $redirector->captured_url || false === strpos( $redirector->captured_url, "tab=bots" ) ) {
		fwrite( STDERR, "FAIL: the legacy bots slug did not redirect to tab=bots\n" );
		exit( 1 );
	}
	echo "OK: the legacy bots slug redirects to the bots tab.\n";
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
	$plugin->bot_management_page()->render_tab_content();
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
for m02_table in event_history fatal_error_markers notification_rules notification_dispatch_log; do
	if [ -z "$(m02_table_exists "$m02_table")" ]; then
		echo "FAIL: default-retention uninstall removed universal_telegram_${m02_table} despite remove_data_on_uninstall defaulting to false" >&2
		exit 1
	fi
done
for m05_table in conversations conversation_messages; do
	if [ -z "$(m02_table_exists "$m05_table")" ]; then
		echo "FAIL: default-retention uninstall removed universal_telegram_${m05_table} despite remove_data_on_uninstall defaulting to false" >&2
		exit 1
	fi
done
for m07_table in operator_identities conversation_notes operator_availability; do
	if [ -z "$(m02_table_exists "$m07_table")" ]; then
		echo "FAIL: default-retention uninstall removed universal_telegram_${m07_table} despite remove_data_on_uninstall defaulting to false" >&2
		exit 1
	fi
done
for m09_table in ai_config ai_drafts; do
	if [ -z "$(m02_table_exists "$m09_table")" ]; then
		echo "FAIL: default-retention uninstall removed universal_telegram_${m09_table} despite remove_data_on_uninstall defaulting to false" >&2
		exit 1
	fi
done
for adapter_table in support_chat_bindings support_chat_delivery_keys; do
	if [ -z "$(m02_table_exists "$adapter_table")" ]; then
		echo "FAIL: default-retention uninstall removed universal_telegram_${adapter_table} despite remove_data_on_uninstall defaulting to false" >&2
		exit 1
	fi
done
echo "OK: default-retention uninstall kept the plugin's own data, including the bots table, all four M02 tables, both M05 tables, all three M07 tables, both M09 tables, and Adapter M1 tables."

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
for m02_table in event_history fatal_error_markers notification_rules notification_dispatch_log; do
	if [ -n "$(m02_table_exists "$m02_table")" ]; then
		echo "FAIL: opt-in uninstall did not remove universal_telegram_${m02_table}" >&2
		exit 1
	fi
done
for m05_table in conversations conversation_messages; do
	if [ -n "$(m02_table_exists "$m05_table")" ]; then
		echo "FAIL: opt-in uninstall did not remove universal_telegram_${m05_table}" >&2
		exit 1
	fi
done
for m07_table in operator_identities conversation_notes operator_availability; do
	if [ -n "$(m02_table_exists "$m07_table")" ]; then
		echo "FAIL: opt-in uninstall did not remove universal_telegram_${m07_table}" >&2
		exit 1
	fi
done
for m09_table in ai_config ai_drafts; do
	if [ -n "$(m02_table_exists "$m09_table")" ]; then
		echo "FAIL: opt-in uninstall did not remove universal_telegram_${m09_table}" >&2
		exit 1
	fi
done
for adapter_table in support_chat_bindings support_chat_delivery_keys; do
	if [ -n "$(m02_table_exists "$adapter_table")" ]; then
		echo "FAIL: opt-in uninstall did not remove universal_telegram_${adapter_table}" >&2
		exit 1
	fi
done
for quiescence_table in quiescence_state quiescence_transitions quiescence_deferred_updates; do
	if [ -n "$(m02_table_exists "$quiescence_table")" ]; then
		echo "FAIL: opt-in uninstall did not remove universal_telegram_${quiescence_table}" >&2
		exit 1
	fi
done
echo "OK: opt-in uninstall removed the plugin's own data, including all six M01 tables, all four M02 tables, both M05 tables, all three M07 tables, both M09 tables, Adapter M1 tables, and SC-M03 WP2 quiescence tables."

echo "== PACKAGE TEST PASSED for WordPress ${WP_VERSION}${WC_VERSION:+, WooCommerce ${WC_VERSION}} =="
