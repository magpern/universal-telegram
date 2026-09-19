<?php
/**
 * Support-desk (fluent-imap-support-desk) presence detection.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Integrations\FluentContactInbox;

use Closure;

/**
 * The single place asking whether the support desk is present, mirroring
 * WooCommerceSupport (docs/adr/0046). Presence is judged by the plugin's version
 * constant, which is defined when its main file is included — before this
 * plugin's own init — because the desk loads its classes lazily and only for
 * some request types, so `class_exists()` at init time would be wrong.
 */
final class FluentContactInboxSupport {

	public const MIN_VERSION = '2.1.0';

	/**
	 * Constructor.
	 *
	 * @param Closure|null $version_reader Returns the desk's version string or null; defaults to the BIOPENTRA_INBOX_VERSION constant. Overridable by tests.
	 */
	public function __construct( private readonly ?Closure $version_reader = null ) {}

	/**
	 * Whether a support desk exposing the lifecycle hooks is active.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		$version = null !== $this->version_reader
			? ( $this->version_reader )()
			: ( defined( 'BIOPENTRA_INBOX_VERSION' ) ? constant( 'BIOPENTRA_INBOX_VERSION' ) : null );

		return is_string( $version ) && version_compare( $version, self::MIN_VERSION, '>=' );
	}
}
