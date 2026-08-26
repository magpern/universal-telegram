<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Migration;

use ActionScheduler;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Migration\DeferredUpdateRepository;
use UniversalTelegram\Migration\QuiescenceGate;
use UniversalTelegram\Migration\QuiescenceTransitionRepository;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Queue\WorkerRunner;
use UniversalTelegram\SupportChatAdapter\ChannelBindingRepository;
use UniversalTelegram\SupportChatAdapter\DeliveryIdempotencyRepository;
use UniversalTelegram\SupportChatAdapter\Outbound\DeliverMessageService;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use WP_UnitTestCase;

/**
 * ADR-0040 §5's required permanent non-interference proof: Support Chat
 * adapter delivery (`DeliverMessageService` → its own `telegram_send_message`
 * job for a channel binding's own destination) is never counted by, or
 * paused by, any quiescence drain query or gate — exercised end-to-end
 * through the real adapter service, not a synthetic enqueue, while
 * state = 'quiescent'.
 */
final class QuiescenceSupportChatNonInterferenceTest extends WP_UnitTestCase {

	private QuiescenceGate $gate;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$wpdb->query( 'UPDATE ' . $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE . " SET state = 'idle', updated_at = NOW() WHERE id = 1" );

		$ids = ActionScheduler::store()->query_actions( array( 'group' => WorkerRunner::GROUP ) );
		foreach ( (array) $ids as $id ) {
			ActionScheduler::store()->delete_action( (int) $id );
		}

		$schema_health = new SchemaHealth();
		$this->gate    = new QuiescenceGate(
			$schema_health,
			new DeferredUpdateRepository( $schema_health, new CredentialVault() ),
			new QuiescenceTransitionRepository()
		);
	}

	public function test_support_chat_adapter_delivery_is_never_counted_or_paused_while_quiescent(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();

		$bots         = new BotProfileRepository( $schema_health, $vault );
		$destinations = new DestinationRepository( $schema_health );
		$bindings     = new ChannelBindingRepository( $schema_health );
		$keys         = new DeliveryIdempotencyRepository( $schema_health );
		$messages     = new OutboundMessageRepository( $schema_health, $vault );

		$bot         = $bots->create( 'Support Chat Bot', str_repeat( 'a', 46 ) );
		$destination = $destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100999', 55, 'Adapter Topic' );

		$binding = $bindings->create(
			wp_generate_uuid4(),
			wp_generate_uuid4(),
			'ensure-non-interference-1',
			$bot->id(),
			$destination->id(),
			1234
		);
		$this->assertNotNull( $binding );

		global $wpdb;
		$wpdb->query( 'UPDATE ' . $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE . " SET state = 'quiescent', entered_quiescent_at = NOW() WHERE id = 1" );

		$service = new DeliverMessageService( $bindings, $keys, $messages, new Dispatcher( $schema_health ) );
		$result  = $service->deliver( $binding->binding_uuid(), 'non-interference-key-1', 'Hello from a visitor', 'Visitor' );

		$this->assertTrue( $result['ok'], 'Support Chat adapter delivery must succeed while state = quiescent (it is never gated by quiescence).' );

		$breakdown = $this->gate->drain_breakdown();
		$this->assertSame( 0, $breakdown['telegram_send_message'], 'A pending action for the adapter\'s own destination_id must never be counted by the drain query.' );

		$confirm_result = ( function () use ( $schema_health, $vault ) {
			global $wpdb;
			$wpdb->query( 'UPDATE ' . $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE . " SET state = 'draining', updated_at = NOW() WHERE id = 1" );
			$gate = new QuiescenceGate( $schema_health, new DeferredUpdateRepository( $schema_health, $vault ), new QuiescenceTransitionRepository() );
			return $gate->confirm();
		} )();

		$this->assertTrue( $confirm_result['success'], 'confirm() must succeed with only Support Chat adapter traffic pending — it must never be treated as legacy-chat work.' );
	}
}
