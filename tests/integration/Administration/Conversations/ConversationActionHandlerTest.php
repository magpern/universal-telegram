<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Conversations;

use UniversalTelegram\Administration\Conversations\ConversationActionHandler;
use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Audit\AuditLogRepository;
use UniversalTelegram\Conversations\OperatorAvailability;
use UniversalTelegram\Conversations\OperatorAvailabilityRepository;
use UniversalTelegram\Conversations\OperatorIdentityRepository;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;
use WP_UnitTestCase;

final class ConversationActionHandlerTest extends WP_UnitTestCase {

	protected function tearDown(): void {
		unset( $_POST['_wpnonce'], $_REQUEST['_wpnonce'], $_POST['op'], $_POST['state'], $_POST['operator_user_id'] );
		parent::tearDown();
	}

	private function handler( OperatorAvailabilityRepository $availability, OperatorIdentityRepository $identities, AuditLogger $audit ): ConversationActionHandler {
		return new class( $availability, $identities, $audit ) extends ConversationActionHandler {
			public ?string $redirected_to = null;

			protected function redirect_and_exit( string $url ): void {
				$this->redirected_to = $url;
			}
		};
	}

	public function test_missing_capability_is_denied_even_with_a_valid_nonce(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$_POST['_wpnonce'] = wp_create_nonce( ConversationActionHandler::NONCE_ACTION );
		$_POST['op']       = 'set_availability';

		$schema_health = new SchemaHealth();
		$handler       = $this->handler( new OperatorAvailabilityRepository( $schema_health ), new OperatorIdentityRepository( $schema_health ), new AuditLogger( $schema_health, new Redactor() ) );

		$this->expectException( \WPDieException::class );
		$handler->handle_request();
	}

	public function test_missing_nonce_is_denied_even_with_capability(): void {
		$operator = self::factory()->user->create();
		$role     = get_role( 'subscriber' );
		$role->add_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		wp_set_current_user( $operator );

		unset( $_POST['_wpnonce'] );
		$_POST['op'] = 'set_availability';

		$schema_health = new SchemaHealth();
		$handler       = $this->handler( new OperatorAvailabilityRepository( $schema_health ), new OperatorIdentityRepository( $schema_health ), new AuditLogger( $schema_health, new Redactor() ) );

		try {
			$this->expectException( \WPDieException::class );
			$handler->handle_request();
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}
	}

	public function test_a_mapped_operator_can_set_their_own_availability(): void {
		$operator = self::factory()->user->create();
		$role     = get_role( 'subscriber' );
		$role->add_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		wp_set_current_user( $operator );

		$schema_health = new SchemaHealth();
		$identities    = new OperatorIdentityRepository( $schema_health );
		$identities->create( $operator, 999888777, null, $operator );
		$availability = new OperatorAvailabilityRepository( $schema_health );

		$nonce                = wp_create_nonce( ConversationActionHandler::NONCE_ACTION );
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;
		$_POST['op']          = 'set_availability';
		$_POST['state']       = OperatorAvailability::BUSY;

		try {
			$handler = $this->handler( $availability, $identities, new AuditLogger( $schema_health, new Redactor() ) );
			$handler->handle_request();
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}

		$found = $availability->find_for_operator( $operator );
		$this->assertNotNull( $found );
		$this->assertSame( OperatorAvailability::BUSY, $found->state() );
	}

	public function test_an_unmapped_operator_cannot_set_their_own_availability(): void {
		$operator = self::factory()->user->create();
		$role     = get_role( 'subscriber' );
		$role->add_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		wp_set_current_user( $operator );

		$schema_health = new SchemaHealth();
		$identities    = new OperatorIdentityRepository( $schema_health );
		$availability  = new OperatorAvailabilityRepository( $schema_health );

		$nonce                = wp_create_nonce( ConversationActionHandler::NONCE_ACTION );
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;
		$_POST['op']          = 'set_availability';
		$_POST['state']       = OperatorAvailability::BUSY;

		try {
			$handler = $this->handler( $availability, $identities, new AuditLogger( $schema_health, new Redactor() ) );
			$handler->handle_request();
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}

		$this->assertNull( $availability->find_for_operator( $operator ) );
	}

	public function test_the_narrower_manage_conversations_capability_cannot_set_another_operators_availability(): void {
		$admin_target = self::factory()->user->create();
		$acting_user  = self::factory()->user->create();
		$role         = get_role( 'subscriber' );
		$role->add_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		wp_set_current_user( $acting_user );

		$schema_health = new SchemaHealth();
		$identities    = new OperatorIdentityRepository( $schema_health );
		$identities->create( $admin_target, 999888777, null, 1 );
		$availability = new OperatorAvailabilityRepository( $schema_health );

		$nonce                       = wp_create_nonce( ConversationActionHandler::NONCE_ACTION );
		$_POST['_wpnonce']           = $nonce;
		$_REQUEST['_wpnonce']        = $nonce;
		$_POST['op']                 = 'set_availability_for_operator';
		$_POST['operator_user_id']   = (string) $admin_target;
		$_POST['state']              = OperatorAvailability::OFFLINE;

		try {
			$handler = $this->handler( $availability, $identities, new AuditLogger( $schema_health, new Redactor() ) );
			$handler->handle_request();
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}

		$this->assertNull( $availability->find_for_operator( $admin_target ) );
	}

	public function test_manage_can_override_another_mapped_operators_availability_and_it_is_audited(): void {
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( $admin );

		$target_operator = self::factory()->user->create();
		$schema_health    = new SchemaHealth();
		$identities       = new OperatorIdentityRepository( $schema_health );
		$identities->create( $target_operator, 999888777, null, $admin );
		$availability = new OperatorAvailabilityRepository( $schema_health );
		$audit        = new AuditLogger( $schema_health, new Redactor() );

		$nonce                     = wp_create_nonce( ConversationActionHandler::NONCE_ACTION );
		$_POST['_wpnonce']         = $nonce;
		$_REQUEST['_wpnonce']      = $nonce;
		$_POST['op']               = 'set_availability_for_operator';
		$_POST['operator_user_id'] = (string) $target_operator;
		$_POST['state']            = OperatorAvailability::OFFLINE;

		$handler = $this->handler( $availability, $identities, $audit );
		$handler->handle_request();

		$found = $availability->find_for_operator( $target_operator );
		$this->assertNotNull( $found );
		$this->assertSame( OperatorAvailability::OFFLINE, $found->state() );
		$this->assertSame( $admin, $found->updated_by() );

		$audit_log = new AuditLogRepository( $schema_health );
		$entries   = array_values(
			array_filter(
				$audit_log->recent( 50 ),
				static function ( array $entry ): bool {
					return 'conversation.operator_availability.set_by_administrator' === $entry['action'];
				}
			)
		);
		$this->assertCount( 1, $entries );
	}
}
