<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Automations;

use UniversalTelegram\Administration\Automations\RuleBuilderRequestHandler;
use UniversalTelegram\Automations\NotificationRuleRepository;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Classification;
use WP_UnitTestCase;

final class RuleBuilderRequestHandlerTest extends WP_UnitTestCase {

	protected function tearDown(): void {
		unset( $_POST['_wpnonce'], $_REQUEST['_wpnonce'], $_POST['op'] );
		parent::tearDown();
	}

	private function registry(): Registry {
		$registry = new Registry();
		$registry->register(
			'wordpress.user_registered',
			1,
			array( 'subject.user_id' => Classification::PUBLIC ),
			array( 'subject.user_id' ),
			array( 'subject.user_id' )
		);

		return $registry;
	}

	private function handler( NotificationRuleRepository $rules ): RuleBuilderRequestHandler {
		return new class( $rules ) extends RuleBuilderRequestHandler {
			public ?string $redirected_to = null;

			protected function redirect_and_exit( string $url ): void {
				$this->redirected_to = $url;
			}
		};
	}

	public function test_missing_capability_is_denied_even_with_a_valid_nonce(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$_POST['_wpnonce'] = wp_create_nonce( RuleBuilderRequestHandler::NONCE_ACTION );
		$_POST['op']       = 'save_rule';

		$rules   = new NotificationRuleRepository( new SchemaHealth(), $this->registry() );
		$handler = $this->handler( $rules );

		$this->expectException( \WPDieException::class );
		$handler->handle_request();
	}

	public function test_missing_nonce_is_denied_even_with_capability(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( $admin );

		unset( $_POST['_wpnonce'] );
		$_POST['op'] = 'save_rule';

		$rules   = new NotificationRuleRepository( new SchemaHealth(), $this->registry() );
		$handler = $this->handler( $rules );

		$this->expectException( \WPDieException::class );
		$handler->handle_request();
	}

	public function test_a_condition_referencing_a_disallowed_field_is_rejected_server_side(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( $admin );

		$nonce                       = wp_create_nonce( RuleBuilderRequestHandler::NONCE_ACTION );
		$_POST['_wpnonce']           = $nonce;
		$_REQUEST['_wpnonce']        = $nonce;
		$_POST['op']                 = 'save_rule';
		$_POST['name']               = 'Test';
		$_POST['event_type']         = 'wordpress.user_registered';
		$_POST['schema_version_min'] = '1';
		$_POST['bot_id']             = '1';
		$_POST['destination_id']     = '1';
		$_POST['template']           = 'x';
		$_POST['priority']           = '100';
		$_POST['cooldown_seconds']   = '0';
		$_POST['conditions']         = array(
			array(
				'field'    => 'subject.not_allowed',
				'operator' => 'equals',
				'value'    => 'x',
			),
		);

		$registry = $this->registry();
		$rules    = new NotificationRuleRepository( new SchemaHealth(), $registry );
		$handler  = $this->handler( $rules );

		$handler->handle_request();

		$this->assertSame( array(), $rules->all() );
	}

	public function test_a_valid_rule_is_saved(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( $admin );

		$nonce                       = wp_create_nonce( RuleBuilderRequestHandler::NONCE_ACTION );
		$_POST['_wpnonce']           = $nonce;
		$_REQUEST['_wpnonce']        = $nonce;
		$_POST['op']                 = 'save_rule';
		$_POST['name']               = 'Test';
		$_POST['event_type']         = 'wordpress.user_registered';
		$_POST['schema_version_min'] = '1';
		$_POST['bot_id']             = '1';
		$_POST['destination_id']     = '1';
		$_POST['template']           = 'x';
		$_POST['priority']           = '100';
		$_POST['cooldown_seconds']   = '0';
		$_POST['conditions']         = array(
			array(
				'field'    => 'subject.user_id',
				'operator' => 'equals',
				'value'    => '1',
			),
		);

		$registry = $this->registry();
		$rules    = new NotificationRuleRepository( new SchemaHealth(), $registry );
		$handler  = $this->handler( $rules );

		$handler->handle_request();

		$this->assertCount( 1, $rules->all() );
	}

	public function test_match_mode_any_is_saved_and_an_incomplete_condition_row_is_dropped(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( $admin );

		$nonce                       = wp_create_nonce( RuleBuilderRequestHandler::NONCE_ACTION );
		$_POST['_wpnonce']           = $nonce;
		$_REQUEST['_wpnonce']        = $nonce;
		$_POST['op']                 = 'save_rule';
		$_POST['name']               = 'Test';
		$_POST['event_type']         = 'wordpress.user_registered';
		$_POST['schema_version_min'] = '1';
		$_POST['bot_id']             = '1';
		$_POST['destination_id']     = '1';
		$_POST['template']           = 'x';
		$_POST['priority']           = '100';
		$_POST['cooldown_seconds']   = '0';
		$_POST['match_mode']         = 'any';
		$_POST['conditions']         = array(
			array(
				'field'    => 'subject.user_id',
				'operator' => 'equals',
				'value'    => '1',
			),
			array(
				'field'    => '',
				'operator' => '',
				'value'    => '',
			),
		);

		$registry = $this->registry();
		$rules    = new NotificationRuleRepository( new SchemaHealth(), $registry );
		$handler  = $this->handler( $rules );

		$handler->handle_request();

		$saved = $rules->all();
		$this->assertCount( 1, $saved );
		$this->assertSame( 'any', $saved[0]->match_mode() );
		$this->assertCount( 1, $saved[0]->conditions() );
	}

	/**
	 * WooCommerce needn't be registered for this test's own Registry, since
	 * PresetCatalog::starter_set()'s three presets only need their event
	 * types and fields to exist for NotificationRuleRepository::save() to
	 * accept them; this test's Registry registers exactly those.
	 */
	private function starter_set_registry(): Registry {
		$registry = new Registry();
		$registry->register(
			'woocommerce.order_created',
			1,
			array(
				'subject.order_id'    => Classification::PUBLIC,
				'context.order_status' => Classification::PUBLIC,
				'payload.order_total' => Classification::PUBLIC,
				'payload.currency'    => Classification::PUBLIC,
				'payload.item_count'  => Classification::PUBLIC,
			),
			array( 'subject.order_id', 'context.order_status', 'payload.order_total', 'payload.currency', 'payload.item_count' ),
			array( 'subject.order_id', 'context.order_status', 'payload.order_total', 'payload.currency', 'payload.item_count' )
		);
		$registry->register(
			'woocommerce.order_failed',
			1,
			array(
				'subject.order_id'    => Classification::PUBLIC,
				'payload.order_total' => Classification::PUBLIC,
				'payload.currency'    => Classification::PUBLIC,
				'payload.status_from' => Classification::PUBLIC,
			),
			array( 'subject.order_id', 'payload.order_total', 'payload.currency', 'payload.status_from' ),
			array( 'subject.order_id', 'payload.order_total', 'payload.currency', 'payload.status_from' )
		);
		$registry->register(
			'woocommerce.stock_threshold_crossed',
			1,
			array(
				'subject.product_id'     => Classification::PUBLIC,
				'payload.status'         => Classification::PUBLIC,
				'payload.stock_quantity' => Classification::PUBLIC,
				'payload.product_sku'    => Classification::PUBLIC,
			),
			array( 'subject.product_id', 'payload.status', 'payload.stock_quantity', 'payload.product_sku' ),
			array( 'subject.product_id', 'payload.status', 'payload.stock_quantity', 'payload.product_sku' )
		);

		return $registry;
	}

	public function test_starter_set_with_no_bot_or_destination_creates_nothing_and_redirects_to_review_with_an_error(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( $admin );

		$nonce                = wp_create_nonce( RuleBuilderRequestHandler::NONCE_ACTION );
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;
		$_POST['op']          = 'create_starter_set';
		$_POST['bot_id']      = '0';
		$_POST['destination_id'] = '0';

		$rules   = new NotificationRuleRepository( new SchemaHealth(), $this->starter_set_registry() );
		$handler = $this->handler( $rules );

		$handler->handle_request();

		$this->assertSame( array(), $rules->all() );
		$this->assertStringContainsString( 'view=starter_set', (string) $handler->redirected_to );
		$this->assertStringContainsString( 'error=missing_destination', (string) $handler->redirected_to );
	}

	public function test_starter_set_confirmation_creates_exactly_three_disabled_draft_rules(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( $admin );

		$nonce                   = wp_create_nonce( RuleBuilderRequestHandler::NONCE_ACTION );
		$_POST['_wpnonce']       = $nonce;
		$_REQUEST['_wpnonce']    = $nonce;
		$_POST['op']             = 'create_starter_set';
		$_POST['bot_id']         = '1';
		$_POST['destination_id'] = '1';

		$rules   = new NotificationRuleRepository( new SchemaHealth(), $this->starter_set_registry() );
		$handler = $this->handler( $rules );

		$handler->handle_request();

		$saved = $rules->all();
		$this->assertCount( 3, $saved );

		foreach ( $saved as $rule ) {
			$this->assertFalse( $rule->enabled() );
			$this->assertStringEndsWith( '(draft)', $rule->name() );
			$this->assertSame( 1, $rule->bot_id() );
			$this->assertSame( 1, $rule->destination_id() );
		}

		$this->assertStringNotContainsString( 'view=starter_set', (string) $handler->redirected_to );
	}
}
