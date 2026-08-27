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
 * The direct counterpart to `InboundAdapterBridgeNonInterferenceTest`
 * (SC-M03 WP9/ADR-0041): a binding activated via
 * `ChannelBindingRepository::activate_prepared()` (docs/adr/0042 §2) **is**
 * claimed by `try_handle()` on the very next call — proving §4.4's claim
 * ("activation requires zero code changes to InboundAdapterBridge") by
 * code, not assertion. Also proves the converse: a binding this test
 * compensates back to `prepared` (`revert_activation()`) is, once again,
 * never claimed — exactly the non-interference guarantee, now shown to
 * survive an activate-then-compensate round trip, not only "never
 * activated at all".
 *
 * @covers \UniversalTelegram\SupportChatAdapter\Inbound\InboundAdapterBridge
 * @covers \UniversalTelegram\SupportChatAdapter\ChannelBindingRepository
 */
final class InboundAdapterBridgeActivationTest extends WP_UnitTestCase {

	public function test_binding_activated_via_activate_prepared_is_claimed_by_try_handle(): void {
		$schema_health = new SchemaHealth();
		$bindings      = new ChannelBindingRepository( $schema_health );
		$bots          = new BotProfileRepository( $schema_health, new CredentialVault() );

		$bot = $bots->create( 'Activation Test Bot', str_repeat( 'b', 46 ) );
		$this->assertNotNull( $bot );

		$destination_id    = 51;
		$telegram_topic_id = 501;
		$binding           = $bindings->create(
			wp_generate_uuid4(),
			wp_generate_uuid4(),
			'ensure-activation-test',
			$bot->id(),
			$destination_id,
			$telegram_topic_id,
			ChannelBinding::STATUS_PREPARED
		);
		$this->assertNotNull( $binding );

		$activated = $bindings->activate_prepared( $binding->binding_uuid(), $binding->cas_version() );
		$this->assertTrue( $activated );

		$refreshed = $bindings->find_by_uuid( $binding->binding_uuid() );
		$this->assertNotNull( $refreshed );
		$this->assertTrue( $refreshed->is_active() );

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
				'text' => 'a reply now routed to the adapter, not legacy',
				'from' => array( 'id' => 999 ),
			),
		);

		$claimed = $bridge->try_handle( $bot, 'chat-id', $telegram_topic_id, $decoded, 1 );

		$this->assertTrue( $claimed, 'An activated binding must be claimed by try_handle() — no adapter code change required.' );
	}

	public function test_binding_compensated_back_to_prepared_is_never_claimed(): void {
		$schema_health = new SchemaHealth();
		$bindings      = new ChannelBindingRepository( $schema_health );
		$bots          = new BotProfileRepository( $schema_health, new CredentialVault() );

		$bot = $bots->create( 'Compensation Test Bot', str_repeat( 'c', 46 ) );
		$this->assertNotNull( $bot );

		$destination_id    = 52;
		$telegram_topic_id = 502;
		$binding           = $bindings->create(
			wp_generate_uuid4(),
			wp_generate_uuid4(),
			'ensure-compensation-test',
			$bot->id(),
			$destination_id,
			$telegram_topic_id,
			ChannelBinding::STATUS_PREPARED
		);
		$this->assertNotNull( $binding );

		$this->assertTrue( $bindings->activate_prepared( $binding->binding_uuid(), $binding->cas_version() ) );
		$active = $bindings->find_by_uuid( $binding->binding_uuid() );
		$this->assertNotNull( $active );
		$this->assertTrue( $active->is_active() );

		$this->assertTrue( $bindings->revert_activation( $binding->binding_uuid(), $active->cas_version() ) );
		$reverted = $bindings->find_by_uuid( $binding->binding_uuid() );
		$this->assertNotNull( $reverted );
		$this->assertSame( ChannelBinding::STATUS_PREPARED, $reverted->status() );
		$this->assertFalse( $reverted->is_active() );
		$this->assertSame( 3, $reverted->cas_version(), 'Monotonic: 1 -> 2 (activate) -> 3 (compensate), never restored to 1.' );

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
				'text' => 'must fall through to legacy after compensation',
				'from' => array( 'id' => 999 ),
			),
		);

		$claimed = $bridge->try_handle( $bot, 'chat-id', $telegram_topic_id, $decoded, 1 );

		$this->assertFalse( $claimed, 'A compensated binding — status back to prepared — must never be claimed, regardless of its now-higher cas_version.' );
	}
}
