<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Telegram\Commands;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Conversations\ChatProfileResolver;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\OperatorAvailabilityRepository;
use UniversalTelegram\Conversations\OperatorIdentityRepository;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Events\EventHistoryRepository;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceCommandQueryService;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Queue\QueueHealth;
use UniversalTelegram\Telegram\Commands\BotCommandDispatcher;
use UniversalTelegram\Telegram\Commands\CommandParser;
use UniversalTelegram\Telegram\Commands\ConfirmationStore;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Outbound\MessageDispatcher;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use WP_UnitTestCase;

/**
 * M08 WP5: /orders, /order, /stock, /sales dispatched end-to-end through
 * BotCommandDispatcher — the WooCommerce-inactive gate, argument-shape
 * validation, and output-content restriction, at the dispatch layer (the
 * underlying query boundary itself is covered directly by
 * WooCommerceCommandQueryServiceTest).
 */
final class BotCommandDispatcherFamilyDTest extends WP_UnitTestCase {

	private SchemaHealth $schema_health;
	private BotProfileRepository $bots;
	private DestinationRepository $destinations;
	private OperatorIdentityRepository $operator_identities;
	private OutboundMessageRepository $outbound_messages;
	private BotCommandDispatcher $dispatcher;

	protected function setUp(): void {
		parent::setUp();

		$this->schema_health = new SchemaHealth();
		$vault               = new CredentialVault();
		$audit               = new AuditLogger( $this->schema_health, new Redactor() );

		$this->bots                = new BotProfileRepository( $this->schema_health, $vault );
		$conversations             = new ConversationRepository( $this->schema_health, new CredentialVault(), new VisitorTokenGenerator() );
		$this->destinations        = new DestinationRepository( $this->schema_health );
		$this->operator_identities = new OperatorIdentityRepository( $this->schema_health );
		$availability              = new OperatorAvailabilityRepository( $this->schema_health );

		$this->outbound_messages = new OutboundMessageRepository( $this->schema_health, $vault );
		$message_dispatcher      = new MessageDispatcher( $this->outbound_messages, new Dispatcher( $this->schema_health ) );

		$this->dispatcher = new BotCommandDispatcher(
			$this->operator_identities,
			$conversations,
			new ChatProfileResolver( $this->bots, $this->destinations ),
			$availability,
			new QueueHealth(),
			new EventHistoryRepository( $this->schema_health, new Registry(), new Redactor() ),
			new WooCommerceSupport(),
			new WooCommerceCommandQueryService(),
			new ConfirmationStore(),
			$message_dispatcher,
			$audit
		);
	}

	private function mapped_operator(): array {
		$operator = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$role     = get_role( 'subscriber' );
		$role->add_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );

		return array( $operator, $role );
	}

	private function last_outbound_text(): ?string {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::OUTBOUND_MESSAGES_TABLE;
		$id    = $wpdb->get_var( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( null === $id ) {
			return null;
		}

		$message = $this->outbound_messages->find( (int) $id );

		return null === $message ? null : $this->outbound_messages->decrypt_body( $message )->plaintext();
	}

	private function handle( $bot, string $command_text, int $entity_length, int $sender_telegram_user_id ): void {
		$parsed = CommandParser::parse(
			array(
				'text'     => $command_text,
				'entities' => array(
					array(
						'type'   => 'bot_command',
						'offset' => 0,
						'length' => $entity_length,
					),
				),
			),
			$bot->telegram_username()
		);

		$this->dispatcher->handle(
			$bot,
			'-100123',
			null,
			$parsed,
			array( 'message' => array( 'from' => array( 'id' => $sender_telegram_user_id ) ) )
		);
	}

	private function fixture(): array {
		$bot = $this->bots->create( 'Support Bot', 'token' );
		$this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Support group' );
		list( $operator_wp_id, $role ) = $this->mapped_operator();
		$this->operator_identities->create( $operator_wp_id, 60, null, 1 );

		return array( $bot, $role );
	}

	public function test_orders_order_stock_sales_return_the_woocommerce_inactive_acknowledgement_when_wc_is_absent(): void {
		if ( getenv( 'UT_TEST_WC_ACTIVE' ) ) {
			$this->markTestSkipped( 'This test asserts the WooCommerce-inactive branch specifically.' );
		}

		list( $bot, $role ) = $this->fixture();

		try {
			$this->handle( $bot, '/orders', 7, 60 );
			$this->assertSame( 'WooCommerce is not active on this site.', $this->last_outbound_text() );

			$this->handle( $bot, '/order 1', 6, 60 );
			$this->assertSame( 'WooCommerce is not active on this site.', $this->last_outbound_text() );

			$this->handle( $bot, '/stock ABC', 6, 60 );
			$this->assertSame( 'WooCommerce is not active on this site.', $this->last_outbound_text() );

			$this->handle( $bot, '/sales today', 6, 60 );
			$this->assertSame( 'WooCommerce is not active on this site.', $this->last_outbound_text() );
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}

	public function test_order_with_a_nonexistent_id_returns_not_found_when_wc_is_active(): void {
		if ( ! getenv( 'UT_TEST_WC_ACTIVE' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active in this configuration.' );
		}

		list( $bot, $role ) = $this->fixture();

		try {
			$this->handle( $bot, '/order 999999999', 6, 60 );
			$this->assertSame( 'Not found or unavailable.', $this->last_outbound_text() );
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}

	public function test_stock_with_a_nonexistent_sku_returns_not_found_when_wc_is_active(): void {
		if ( ! getenv( 'UT_TEST_WC_ACTIVE' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active in this configuration.' );
		}

		list( $bot, $role ) = $this->fixture();

		try {
			$this->handle( $bot, '/stock no-such-sku-here', 6, 60 );
			$this->assertSame( 'Not found or unavailable.', $this->last_outbound_text() );
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}

	public function test_orders_reports_a_real_count_when_wc_is_active(): void {
		if ( ! getenv( 'UT_TEST_WC_ACTIVE' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active in this configuration.' );
		}

		list( $bot, $role ) = $this->fixture();

		try {
			$this->handle( $bot, '/orders', 7, 60 );
			$this->assertMatchesRegularExpression( '/^Orders \(24h\): \d+$/', (string) $this->last_outbound_text() );
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}

	public function test_order_malformed_argument_gets_the_generic_malformed_acknowledgement(): void {
		list( $bot, $role ) = $this->fixture();

		try {
			$this->handle( $bot, '/order not-a-number', 6, 60 );
			$this->assertSame( 'Unrecognized command syntax. Send /help for the command list.', $this->last_outbound_text() );
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}

	public function test_sales_malformed_argument_gets_the_generic_malformed_acknowledgement(): void {
		list( $bot, $role ) = $this->fixture();

		try {
			$this->handle( $bot, '/sales year', 6, 60 );
			$this->assertSame( 'Unrecognized command syntax. Send /help for the command list.', $this->last_outbound_text() );
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}

	public function test_orders_order_stock_sales_are_general_topic_only(): void {
		list( $bot, $role ) = $this->fixture();

		try {
			$conversations = new ConversationRepository( $this->schema_health, new CredentialVault(), new VisitorTokenGenerator() );
			$conversation  = $conversations->create( 'uuid-wp5-topic', 'hash', $bot->id(), null );
			$destination   = $this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', 88, 'Topic' );
			$conversations->mark_topic_created( $conversation->id(), 88, $destination->id() );

			$parsed = CommandParser::parse(
				array(
					'text'     => '/orders',
					'entities' => array(
						array(
							'type'   => 'bot_command',
							'offset' => 0,
							'length' => 7,
						),
					),
				),
				$bot->telegram_username()
			);

			$this->dispatcher->handle( $bot, '-100123', 88, $parsed, array( 'message' => array( 'from' => array( 'id' => 60 ) ) ) );

			$this->assertSame( "This command isn't available here.", $this->last_outbound_text() );
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}
}
