<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Conversations;

use UniversalTelegram\Administration\Conversations\OperatorIdentityRequestHandler;
use UniversalTelegram\Conversations\OperatorIdentityRepository;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Persistence\SchemaHealth;
use WP_UnitTestCase;

final class OperatorIdentityRequestHandlerTest extends WP_UnitTestCase {

	protected function tearDown(): void {
		unset( $_POST['_wpnonce'], $_REQUEST['_wpnonce'], $_POST['op'], $_POST['wp_user_id'], $_POST['telegram_user_id'], $_POST['telegram_username'] );
		parent::tearDown();
	}

	private function handler( OperatorIdentityRepository $identities ): OperatorIdentityRequestHandler {
		return new class( $identities ) extends OperatorIdentityRequestHandler {
			public ?string $redirected_to = null;

			protected function redirect_and_exit( string $url ): void {
				$this->redirected_to = $url;
			}
		};
	}

	public function test_missing_capability_is_denied_even_with_a_valid_nonce(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$_POST['_wpnonce'] = wp_create_nonce( OperatorIdentityRequestHandler::NONCE_ACTION );
		$_POST['op']       = 'create_mapping';

		$identities = new OperatorIdentityRepository( new SchemaHealth() );
		$handler    = $this->handler( $identities );

		$this->expectException( \WPDieException::class );
		$handler->handle_request();
	}

	public function test_missing_nonce_is_denied_even_with_capability(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( $admin );

		unset( $_POST['_wpnonce'] );
		$_POST['op'] = 'create_mapping';

		$identities = new OperatorIdentityRepository( new SchemaHealth() );
		$handler    = $this->handler( $identities );

		$this->expectException( \WPDieException::class );
		$handler->handle_request();
	}

	public function test_the_narrower_manage_conversations_capability_alone_is_not_sufficient(): void {
		// Mapping creation is MANAGE-gated (administrator-only), not
		// MANAGE_CONVERSATIONS (operator self-service), since it grants
		// inbound Telegram operator-acting trust (ADR-0026).
		$operator = self::factory()->user->create();
		$role     = get_role( 'subscriber' );
		$role->add_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		wp_set_current_user( $operator );

		$_POST['_wpnonce'] = wp_create_nonce( OperatorIdentityRequestHandler::NONCE_ACTION );
		$_POST['op']       = 'create_mapping';

		$identities = new OperatorIdentityRepository( new SchemaHealth() );
		$handler    = $this->handler( $identities );

		try {
			$this->expectException( \WPDieException::class );
			$handler->handle_request();
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}

	public function test_a_valid_mapping_is_created(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( $admin );

		$operator = self::factory()->user->create();

		$nonce                       = wp_create_nonce( OperatorIdentityRequestHandler::NONCE_ACTION );
		$_POST['_wpnonce']           = $nonce;
		$_REQUEST['_wpnonce']        = $nonce;
		$_POST['op']                 = 'create_mapping';
		$_POST['wp_user_id']         = (string) $operator;
		$_POST['telegram_user_id']   = '999888777';
		$_POST['telegram_username']  = 'opuser';

		$identities = new OperatorIdentityRepository( new SchemaHealth() );
		$handler    = $this->handler( $identities );

		$handler->handle_request();

		$found = $identities->find_by_wp_user_id( $operator );
		$this->assertNotNull( $found );
		$this->assertSame( 999888777, $found->telegram_user_id() );
	}

	public function test_delete_mapping_removes_it(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( $admin );

		$operator   = self::factory()->user->create();
		$identities = new OperatorIdentityRepository( new SchemaHealth() );
		$identities->create( $operator, 999888777, null, $admin );

		$nonce                = wp_create_nonce( OperatorIdentityRequestHandler::NONCE_ACTION );
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;
		$_POST['op']          = 'delete_mapping';
		$_POST['wp_user_id']  = (string) $operator;

		$handler = $this->handler( $identities );
		$handler->handle_request();

		$this->assertNull( $identities->find_by_wp_user_id( $operator ) );
	}
}
