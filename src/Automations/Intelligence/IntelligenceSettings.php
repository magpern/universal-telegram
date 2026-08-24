<?php
/**
 * Typed reader over the M11B operational-intelligence Settings fields.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations\Intelligence;

use UniversalTelegram\Core\Configuration\Settings;

/**
 * Thin, typed accessor over the operational_summary_* and alert_* fields
 * Core\Configuration\Settings owns (docs/plans/m11b-digests-and-operational-intelligence-plan-v1.md
 * §5), used by OperationalSummarySweep, AlertEvaluator, and the
 * Intelligence admin panel so none of them read the raw settings array
 * directly. Mirrors the read-only-typed-accessor role
 * Automations\Digest\DigestEligibility already plays for the M11A
 * visitor_digest_* fields, without duplicating that class's eligibility
 * logic (reused as-is via constructor injection by callers, §1).
 */
final class IntelligenceSettings {

	public const ALERT_TYPES = array(
		'checkout_failure_count',
		'order_failure_spike',
		'js_error_spike',
	);

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Supplies the operational_summary_* and alert_* fields.
	 */
	public function __construct( private readonly Settings $settings ) {}

	/**
	 * Whether the operational summary is enabled.
	 *
	 * @return bool
	 */
	public function operational_summary_enabled(): bool {
		return true === (bool) $this->settings->get()['operational_summary_enabled'];
	}

	/**
	 * Configured operational-summary bot id, or null.
	 *
	 * @return int|null
	 */
	public function operational_summary_bot_id(): ?int {
		$value = $this->settings->get()['operational_summary_bot_id'];

		return null === $value ? null : (int) $value;
	}

	/**
	 * Configured operational-summary destination id, or null.
	 *
	 * @return int|null
	 */
	public function operational_summary_destination_id(): ?int {
		$value = $this->settings->get()['operational_summary_destination_id'];

		return null === $value ? null : (int) $value;
	}

	/**
	 * UTC hour when the operational summary should run.
	 *
	 * @return int
	 */
	public function operational_summary_hour_utc(): int {
		return (int) $this->settings->get()['operational_summary_hour_utc'];
	}

	/**
	 * Configured alert bot id, or null.
	 *
	 * @return int|null
	 */
	public function alert_bot_id(): ?int {
		$value = $this->settings->get()['alert_bot_id'];

		return null === $value ? null : (int) $value;
	}

	/**
	 * Configured alert destination id, or null.
	 *
	 * @return int|null
	 */
	public function alert_destination_id(): ?int {
		$value = $this->settings->get()['alert_destination_id'];

		return null === $value ? null : (int) $value;
	}

	/**
	 * Whether the given fixed alert type is currently enabled.
	 *
	 * @param string $alert_type One of self::ALERT_TYPES.
	 *
	 * @return bool
	 */
	public function alert_enabled( string $alert_type ): bool {
		if ( ! in_array( $alert_type, self::ALERT_TYPES, true ) ) {
			return false;
		}

		return true === (bool) $this->settings->get()[ 'alert_' . $alert_type . '_enabled' ];
	}

	/**
	 * The given fixed alert type's configured threshold.
	 *
	 * @param string $alert_type One of self::ALERT_TYPES.
	 *
	 * @return int
	 */
	public function alert_threshold( string $alert_type ): int {
		if ( ! in_array( $alert_type, self::ALERT_TYPES, true ) ) {
			return 0;
		}

		return (int) $this->settings->get()[ 'alert_' . $alert_type . '_threshold' ];
	}
}
