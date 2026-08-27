<?php
/**
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\SupportChatAdapter\Migration;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Conversations\OperatorIdentityRepository;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\SupportChatAdapter\ChannelBinding;
use UniversalTelegram\SupportChatAdapter\ChannelBindingRepository;
use UniversalTelegram\SupportChatAdapter\DiscoveryClient;
use UniversalTelegram\SupportChatAdapter\Inbound\InboundAdapterBridge;
use UniversalTelegram\SupportChatAdapter\Inbound\SupportChatContractClient;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use WP_UnitTestCase;

/**
 * Support Chat ADR-0009 §3 / this repository's ADR-0041 §3's single most
 * load-bearing test: a `prepared`-only binding — the only status SC-M03
 * work package 5's LegacyBindingImportServiceV1 ever writes — must never be
 * claimed by InboundAdapterBridge::try_handle(), across every ADR-0040
 * quiescence state. This is the direct, code-level proof that "preparation"
 * cannot itself constitute a live route switch, replacing the earlier,
 * disproven assumption that a legacy webhook's own fate governs safety.
 *
 * @covers \UniversalTelegram\SupportChatAdapter\Inbound\InboundAdapterBridge
 */
final class InboundAdapterBridgeNonInterferenceTest extends WP_UnitTestCase {

	/**
	 * @dataProvider quiescence_states
	 */
	public function test_prepared_binding_is_never_claimed( string $quiescence_state ): void {
		global $wpdb;
		$state_table = $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$state_table} SET state = %s, updated_at = NOW() WHERE id = 1", $quiescence_state ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$schema_health = new SchemaHealth();
		$bindings      = new ChannelBindingRepository( $schema_health );
		$bots          = new BotProfileRepository( $schema_health, new CredentialVault() );

		$bot = $bots->create( 'Non-Interference Test Bot', str_repeat( 'a', 46 ) );
		$this->assertNotNull( $bot );

		$destination_id    = 50;
		$telegram_topic_id = 500;
		$binding           = $bindings->create(
			wp_generate_uuid4(),
			wp_generate_uuid4(),
			'ensure-non-interference-prepared',
			$bot->id(),
			$destination_id,
			$telegram_topic_id,
			ChannelBinding::STATUS_PREPARED
		);
		$this->assertNotNull( $binding );
		$this->assertSame( ChannelBinding::STATUS_PREPARED, $binding->status() );

		$bridge = new InboundAdapterBridge(
			$bindings,
			new DiscoveryClient(),
			new SupportChatContractClient(),
			new OperatorIdentityRepository( $schema_health ),
			new AuditLogger( $schema_health, new Redactor() ),
			true
		);

		$decoded = array(
			'update_id' => 1,
			'message'   => array(
				'text' => 'a reply that must fall through to legacy routing',
				'from' => array( 'id' => 999 ),
			),
		);

		$claimed = $bridge->try_handle( $bot, 'chat-id', $telegram_topic_id, $decoded, 1 );

		$this->assertFalse(
			$claimed,
			"A binding with status='prepared' must never be claimed by try_handle() while quiescence state is '{$quiescence_state}'."
		);
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function quiescence_states(): array {
		return array(
			'idle'      => array( 'idle' ),
			'draining'  => array( 'draining' ),
			'quiescent' => array( 'quiescent' ),
			'replaying' => array( 'replaying' ),
		);
	}
}
