<?php
/**
 * Diagnostics report data.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Diagnostics;

use UniversalTelegram\Audit\AuditLogRepository;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Queue\QueueHealth;

/**
 * Gathers plugin, PHP, WordPress, and WooCommerce version information, the
 * queue's own pending/failed counts, and the most recent (already
 * redacted) audit-log entries.
 */
final class DiagnosticsReport {

	/**
	 * Pending/failed action counts.
	 *
	 * @var QueueHealth
	 */
	private QueueHealth $queue_health;

	/**
	 * The recent-entries read path.
	 *
	 * @var AuditLogRepository
	 */
	private AuditLogRepository $audit_log_repository;

	/**
	 * WooCommerce-presence detection.
	 *
	 * @var WooCommerceSupport
	 */
	private WooCommerceSupport $woocommerce_support;

	/**
	 * The current schema-availability state.
	 *
	 * @var SchemaHealth
	 */
	private SchemaHealth $schema_health;

	/**
	 * Constructor.
	 *
	 * @param QueueHealth        $queue_health          Pending/failed action counts.
	 * @param AuditLogRepository $audit_log_repository  The recent-entries read path.
	 * @param WooCommerceSupport $woocommerce_support    WooCommerce-presence detection.
	 * @param SchemaHealth       $schema_health          The current schema-availability state.
	 */
	public function __construct(
		QueueHealth $queue_health,
		AuditLogRepository $audit_log_repository,
		WooCommerceSupport $woocommerce_support,
		SchemaHealth $schema_health
	) {
		$this->queue_health         = $queue_health;
		$this->audit_log_repository = $audit_log_repository;
		$this->woocommerce_support  = $woocommerce_support;
		$this->schema_health        = $schema_health;
	}

	/**
	 * Generates the report data.
	 *
	 * @return array{
	 *     plugin_version: string,
	 *     php_version: string,
	 *     wp_version: string,
	 *     woocommerce_active: bool,
	 *     queue_pending: int,
	 *     queue_failed: int,
	 *     recent_audit_entries: array<int, array<string, mixed>>
	 * }
	 */
	public function generate(): array {
		return array(
			'plugin_version'       => defined( 'UNIVERSAL_TELEGRAM_VERSION' ) ? UNIVERSAL_TELEGRAM_VERSION : 'unknown',
			'php_version'          => PHP_VERSION,
			'wp_version'           => get_bloginfo( 'version' ),
			'woocommerce_active'   => $this->woocommerce_support->is_active(),
			'queue_pending'        => $this->schema_health->is_available() ? $this->queue_health->pending_count() : 0,
			'queue_failed'         => $this->schema_health->is_available() ? $this->queue_health->failed_count() : 0,
			'recent_audit_entries' => $this->audit_log_repository->recent( 20 ),
		);
	}
}
