<?php
/**
 * Plugin deactivation.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Core\Lifecycle;

use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\SupportChatAdapter\ChannelBindingRepository;
use UniversalTelegram\SupportChatAdapter\Inbound\SupportChatContractClient;

/**
 * Deactivation callback. Reports open Support Chat adapter bindings as
 * channel-unavailable (fail-closed for Telegram only) and marks them
 * unavailable locally. Does not remove data or stop non-adapter features.
 */
final class Deactivator {

	/**
	 * Deactivation callback.
	 */
	public function deactivate(): void {
		$schema_health = new SchemaHealth();
		if ( ! $schema_health->is_available() ) {
			return;
		}

		$bindings = new ChannelBindingRepository( $schema_health );
		$client   = new SupportChatContractClient();
		$uuids    = $bindings->list_active_binding_uuids( 200 );

		foreach ( $uuids as $uuid ) {
			$client->report_channel_unavailable( $uuid, 'adapter_deactivated' );
		}

		$bindings->mark_all_active_unavailable();
	}
}
