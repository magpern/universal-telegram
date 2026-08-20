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
		$_POST['conditions_json']    = wp_json_encode(
			array(
				array(
					'field'    => 'subject.not_allowed',
					'operator' => 'equals',
					'value'    => 'x',
				),
			)
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
		$_POST['conditions_json']    = wp_json_encode(
			array(
				array(
					'field'    => 'subject.user_id',
					'operator' => 'equals',
					'value'    => 1,
				),
			)
		);

		$registry = $this->registry();
		$rules    = new NotificationRuleRepository( new SchemaHealth(), $registry );
		$handler  = $this->handler( $rules );

		$handler->handle_request();

		$this->assertCount( 1, $rules->all() );
	}
}
