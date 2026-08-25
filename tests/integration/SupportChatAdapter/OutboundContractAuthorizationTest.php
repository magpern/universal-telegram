<?php
/**
 * Integration tests for outbound Contract acceptor authorization.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\SupportChatAdapter;

use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\SupportChatAdapter\ChannelBindingRepository;
use UniversalTelegram\SupportChatAdapter\ContractConstants;
use UniversalTelegram\SupportChatAdapter\DeliveryIdempotencyRepository;
use UniversalTelegram\SupportChatAdapter\DiscoveryClient;
use UniversalTelegram\SupportChatAdapter\Outbound\BackfillService;
use UniversalTelegram\SupportChatAdapter\Outbound\DeliverMessageService;
use UniversalTelegram\SupportChatAdapter\Outbound\EnsureChannelCaseService;
use UniversalTelegram\SupportChatAdapter\Outbound\NotifyOperatorsService;
use UniversalTelegram\SupportChatAdapter\Outbound\OutboundContractController;
use UniversalTelegram\Telegram\Client\TelegramApiClient;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @covers \UniversalTelegram\SupportChatAdapter\Outbound\OutboundContractController
 */
final class OutboundContractAuthorizationTest extends WP_UnitTestCase {

	private OutboundContractController $controller;

	private ChannelBindingRepository $bindings;

	protected function setUp(): void {
		parent::setUp();

		$schema         = new SchemaHealth();
		$settings       = new Settings();
		$this->bindings = new ChannelBindingRepository( $schema );
		$delivery       = new DeliveryIdempotencyRepository( $schema );
		$bots           = new BotProfileRepository( $schema, new CredentialVault() );
		$destinations   = new DestinationRepository( $schema );
		$messages       = new OutboundMessageRepository( $schema, new CredentialVault() );
		$dispatcher     = new Dispatcher( $schema );

		$ensure   = new EnsureChannelCaseService( $this->bindings, $bots, $destinations, new TelegramApiClient() );
		$deliver  = new DeliverMessageService( $this->bindings, $delivery, $messages, $dispatcher );
		$notify   = new NotifyOperatorsService( $deliver );
		$backfill = new BackfillService( $deliver );

		$this->controller = new OutboundContractController(
			new DiscoveryClient(),
			$settings,
			$destinations,
			$ensure,
			$notify,
			$backfill,
			$deliver
		);
		add_action( 'rest_api_init', array( $this->controller, 'register_routes' ) );
		do_action( 'rest_api_init' );

		update_option(
			Settings::OPTION_NAME,
			array_merge(
				$settings->get(),
				array(
					'support_chat_adapter_enabled' => true,
				)
			)
		);

		// Clear any prior auth filter from other tests.
		remove_all_filters( 'universal_telegram_support_chat_adapter_rest_authorized' );
	}

	public function test_unauthenticated_ensure_is_rejected_and_creates_no_binding(): void {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/' . ContractConstants::UT_REST_NAMESPACE . ContractConstants::UT_REST_PREFIX . '/ensure_channel_case' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'conversation_uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
					'idempotency_key'   => 'ensure-unauth-1',
				)
			)
		);

		$response = rest_do_request( $request );
		$this->assertTrue( $response->is_error() || $response->get_status() >= 400 );
		$this->assertNull( $this->bindings->find_by_conversation_uuid( 'cccccccc-cccc-cccc-cccc-cccccccccccc' ) );
	}

	public function test_support_chat_manage_alone_cannot_ensure_or_deliver(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );
		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'universal_support_chat_manage' );
		wp_set_current_user( $user_id );

		$ensure = new WP_REST_Request( 'POST', '/' . ContractConstants::UT_REST_NAMESPACE . ContractConstants::UT_REST_PREFIX . '/ensure_channel_case' );
		$ensure->set_header( 'Content-Type', 'application/json' );
		$ensure->set_body(
			wp_json_encode(
				array(
					'conversation_uuid' => 'dddddddd-dddd-dddd-dddd-dddddddddddd',
					'idempotency_key'   => 'ensure-sc-manage-1',
				)
			)
		);
		$ensure_response = rest_do_request( $ensure );
		$this->assertTrue( $ensure_response->is_error() || $ensure_response->get_status() >= 400 );
		$this->assertNull( $this->bindings->find_by_conversation_uuid( 'dddddddd-dddd-dddd-dddd-dddddddddddd' ) );

		$deliver = new WP_REST_Request( 'POST', '/' . ContractConstants::UT_REST_NAMESPACE . ContractConstants::UT_REST_PREFIX . '/deliver_message' );
		$deliver->set_header( 'Content-Type', 'application/json' );
		$deliver->set_body(
			wp_json_encode(
				array(
					'channel_case_ref' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee',
					'idempotency_key'  => 'deliver-sc-manage-1',
					'body'             => 'should not enqueue',
				)
			)
		);
		$deliver_response = rest_do_request( $deliver );
		$this->assertTrue( $deliver_response->is_error() || $deliver_response->get_status() >= 400 );
	}

	public function test_ut_manage_alone_cannot_mutate_while_contract_unavailable(): void {
		( new CapabilityRegistrar() )->grant_to_administrator();
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$request = new WP_REST_Request( 'POST', '/' . ContractConstants::UT_REST_NAMESPACE . ContractConstants::UT_REST_PREFIX . '/ensure_channel_case' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'conversation_uuid' => 'ffffffff-ffff-ffff-ffff-ffffffffffff',
					'idempotency_key'   => 'ensure-ut-manage-1',
				)
			)
		);

		$response = rest_do_request( $request );
		// Permission fails (filter default false / discovery not Compatible) —
		// never creates a binding or Telegram topic.
		$this->assertTrue( $response->is_error() || $response->get_status() >= 400 );
		$this->assertNull( $this->bindings->find_by_conversation_uuid( 'ffffffff-ffff-ffff-ffff-ffffffffffff' ) );
	}
}
