<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Conversations;

use UniversalTelegram\Administration\Conversations\ConversationActionHandler;
use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Conversations\ConversationNoteRepository;
use UniversalTelegram\Conversations\ConversationPurgeService;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\ConversationStatus;
use UniversalTelegram\Conversations\ConversationTopicEligibility;
use UniversalTelegram\Conversations\TopicDeletionDispatcher;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Conversations\MessageRepository;
use UniversalTelegram\Conversations\OperatorAvailability;
use UniversalTelegram\Conversations\OperatorAvailabilityRepository;
use UniversalTelegram\Conversations\OperatorIdentityRepository;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use WP_UnitTestCase;

final class ConversationActionHandlerTest extends WP_UnitTestCase {

	protected function tearDown(): void {
		unset(
			$_POST['_wpnonce'],
			$_REQUEST['_wpnonce'],
			$_POST['op'],
			$_POST['state'],
			$_POST['operator_user_id'],
			$_POST['conversation_id'],
			$_POST['new_operator_id'],
			$_POST['expected_operator_id'],
			$_POST['override'],
			$_POST['body'],
			$_POST['confirm'],
			$_POST['conversation_ids']
		);
		parent::tearDown();
	}

	/**
	 * @return array{0: ConversationActionHandler, 1: OperatorAvailabilityRepository, 2: OperatorIdentityRepository, 3: ConversationRepository, 4: ConversationNoteRepository, 5: AuditLogger, 6: MessageRepository, 7: DestinationRepository}
	 */
	private function fixture(): array {
		$schema_health  = new SchemaHealth();
		$availability   = new OperatorAvailabilityRepository( $schema_health );
		$identities     = new OperatorIdentityRepository( $schema_health );
		$conversations  = new ConversationRepository( $schema_health, new CredentialVault(), new VisitorTokenGenerator() );
		$notes          = new ConversationNoteRepository( $schema_health, new CredentialVault() );
		$messages       = new MessageRepository( $schema_health, new CredentialVault() );
		$destinations   = new DestinationRepository( $schema_health );
		$purge_service  = new ConversationPurgeService( $conversations, $messages, $destinations );
		$eligibility    = new ConversationTopicEligibility( $conversations, $destinations );
		$topic_deletion = new TopicDeletionDispatcher( $conversations, new Dispatcher( $schema_health ) );
		$audit          = new AuditLogger( $schema_health, new Redactor() );

		$handler = new class( $availability, $identities, $conversations, $notes, $purge_service, $audit, $eligibility, $topic_deletion ) extends ConversationActionHandler {
			public ?string $redirected_to = null;

			protected function redirect_and_exit( string $url ): void {
				$this->redirected_to = $url;
			}
		};

		return array( $handler, $availability, $identities, $conversations, $notes, $audit, $messages, $destinations );
	}

	public function test_missing_capability_is_denied_even_with_a_valid_nonce(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$_POST['_wpnonce'] = wp_create_nonce( ConversationActionHandler::NONCE_ACTION );
		$_POST['op']       = 'set_availability';

		list( $handler ) = $this->fixture();

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

		list( $handler ) = $this->fixture();

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

		list( $handler, $availability, $identities ) = $this->fixture();
		$identities->create( $operator, 999888777, null, $operator );

		$nonce                = wp_create_nonce( ConversationActionHandler::NONCE_ACTION );
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;
		$_POST['op']          = 'set_availability';
		$_POST['state']       = OperatorAvailability::BUSY;

		try {
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

		list( $handler, $availability ) = $this->fixture();

		$nonce                = wp_create_nonce( ConversationActionHandler::NONCE_ACTION );
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;
		$_POST['op']          = 'set_availability';
		$_POST['state']       = OperatorAvailability::BUSY;

		try {
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

		list( $handler, $availability, $identities ) = $this->fixture();
		$identities->create( $admin_target, 999888777, null, 1 );

		$nonce                     = wp_create_nonce( ConversationActionHandler::NONCE_ACTION );
		$_POST['_wpnonce']         = $nonce;
		$_REQUEST['_wpnonce']      = $nonce;
		$_POST['op']               = 'set_availability_for_operator';
		$_POST['operator_user_id'] = (string) $admin_target;
		$_POST['state']            = OperatorAvailability::OFFLINE;

		try {
			$handler->handle_request();
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}

		$this->assertNull( $availability->find_for_operator( $admin_target ) );
	}

	public function test_manage_can_override_another_mapped_operators_availability_and_it_is_audited(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( $admin );

		list( $handler, $availability, $identities, , , $audit ) = $this->fixture();
		$target_operator = self::factory()->user->create();
		$identities->create( $target_operator, 999888777, null, $admin );

		$nonce                     = wp_create_nonce( ConversationActionHandler::NONCE_ACTION );
		$_POST['_wpnonce']         = $nonce;
		$_REQUEST['_wpnonce']      = $nonce;
		$_POST['op']               = 'set_availability_for_operator';
		$_POST['operator_user_id'] = (string) $target_operator;
		$_POST['state']            = OperatorAvailability::OFFLINE;

		$handler->handle_request();

		$found = $availability->find_for_operator( $target_operator );
		$this->assertNotNull( $found );
		$this->assertSame( OperatorAvailability::OFFLINE, $found->state() );
		$this->assertSame( $admin, $found->updated_by() );

		$this->assertActionRecorded( 'conversation.operator_availability.set_by_administrator' );
	}

	public function test_assign_succeeds_when_expectation_matches(): void {
		$operator = self::factory()->user->create();
		$role     = get_role( 'subscriber' );
		$role->add_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		wp_set_current_user( $operator );

		list( $handler, , $identities, $conversations, , $audit ) = $this->fixture();
		$target_operator = self::factory()->user->create();
		$identities->create( $target_operator, 999888777, null, 1 );
		$conversation = $conversations->create( 'uuid-handler-assign-1', 'hash', 1, null );

		$nonce                         = wp_create_nonce( ConversationActionHandler::NONCE_ACTION );
		$_POST['_wpnonce']             = $nonce;
		$_REQUEST['_wpnonce']          = $nonce;
		$_POST['op']                   = 'assign';
		$_POST['conversation_id']      = (string) $conversation->id();
		$_POST['new_operator_id']      = (string) $target_operator;
		$_POST['expected_operator_id'] = '';

		try {
			$handler->handle_request();
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}

		$this->assertSame( $target_operator, $conversations->find( $conversation->id() )->assigned_operator_id() );
		$this->assertActionRecorded( 'conversation.assignment.set' );
	}

	public function test_assign_with_a_stale_expectation_makes_no_change_and_is_not_audited(): void {
		$operator = self::factory()->user->create();
		$role     = get_role( 'subscriber' );
		$role->add_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		wp_set_current_user( $operator );

		list( $handler, , $identities, $conversations, , $audit ) = $this->fixture();
		$first_operator  = self::factory()->user->create();
		$second_operator = self::factory()->user->create();
		$identities->create( $second_operator, 999888777, null, 1 );
		$conversation = $conversations->create( 'uuid-handler-assign-2', 'hash', 1, null );
		$conversations->assign( $conversation->id(), $first_operator );

		$nonce                         = wp_create_nonce( ConversationActionHandler::NONCE_ACTION );
		$_POST['_wpnonce']             = $nonce;
		$_REQUEST['_wpnonce']          = $nonce;
		$_POST['op']                   = 'assign';
		$_POST['conversation_id']      = (string) $conversation->id();
		$_POST['new_operator_id']      = (string) $second_operator;
		$_POST['expected_operator_id'] = '';

		try {
			$handler->handle_request();
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}

		$this->assertSame( $first_operator, $conversations->find( $conversation->id() )->assigned_operator_id() );
		$this->assertActionNotRecorded( 'conversation.assignment.set' );
	}

	public function test_assigning_a_busy_operator_is_blocked_without_an_override(): void {
		$operator = self::factory()->user->create();
		$role     = get_role( 'subscriber' );
		$role->add_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		wp_set_current_user( $operator );

		list( $handler, $availability, $identities, $conversations ) = $this->fixture();
		$target_operator = self::factory()->user->create();
		$identities->create( $target_operator, 999888777, null, 1 );
		$availability->set_state( $target_operator, OperatorAvailability::BUSY, $target_operator );
		$conversation = $conversations->create( 'uuid-handler-assign-3', 'hash', 1, null );

		$nonce                         = wp_create_nonce( ConversationActionHandler::NONCE_ACTION );
		$_POST['_wpnonce']             = $nonce;
		$_REQUEST['_wpnonce']          = $nonce;
		$_POST['op']                   = 'assign';
		$_POST['conversation_id']      = (string) $conversation->id();
		$_POST['new_operator_id']      = (string) $target_operator;
		$_POST['expected_operator_id'] = '';

		try {
			$handler->handle_request();
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}

		$this->assertNull( $conversations->find( $conversation->id() )->assigned_operator_id() );
	}

	public function test_manage_can_override_a_busy_operator_assignment_and_it_is_audited(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( $admin );

		list( $handler, $availability, $identities, $conversations, , $audit ) = $this->fixture();
		$target_operator = self::factory()->user->create();
		$identities->create( $target_operator, 999888777, null, $admin );
		$availability->set_state( $target_operator, OperatorAvailability::OFFLINE, $target_operator );
		$conversation = $conversations->create( 'uuid-handler-assign-4', 'hash', 1, null );

		$nonce                         = wp_create_nonce( ConversationActionHandler::NONCE_ACTION );
		$_POST['_wpnonce']             = $nonce;
		$_REQUEST['_wpnonce']          = $nonce;
		$_POST['op']                   = 'assign';
		$_POST['conversation_id']      = (string) $conversation->id();
		$_POST['new_operator_id']      = (string) $target_operator;
		$_POST['expected_operator_id'] = '';
		$_POST['override']             = '1';

		$handler->handle_request();

		$this->assertSame( $target_operator, $conversations->find( $conversation->id() )->assigned_operator_id() );
		$this->assertActionRecorded( 'conversation.assignment.set_with_availability_override' );
	}

	public function test_unassign_clears_assignment(): void {
		$operator = self::factory()->user->create();
		$role     = get_role( 'subscriber' );
		$role->add_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		wp_set_current_user( $operator );

		list( $handler, , , $conversations ) = $this->fixture();
		$conversation                        = $conversations->create( 'uuid-handler-unassign-1', 'hash', 1, null );
		$conversations->assign( $conversation->id(), $operator );

		$nonce                         = wp_create_nonce( ConversationActionHandler::NONCE_ACTION );
		$_POST['_wpnonce']             = $nonce;
		$_REQUEST['_wpnonce']          = $nonce;
		$_POST['op']                   = 'unassign';
		$_POST['conversation_id']      = (string) $conversation->id();
		$_POST['expected_operator_id'] = (string) $operator;

		try {
			$handler->handle_request();
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}

		$this->assertNull( $conversations->find( $conversation->id() )->assigned_operator_id() );
	}

	public function test_reopen_transitions_resolved_to_open(): void {
		$operator = self::factory()->user->create();
		$role     = get_role( 'subscriber' );
		$role->add_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		wp_set_current_user( $operator );

		list( $handler, , , $conversations ) = $this->fixture();
		$conversation                        = $conversations->create( 'uuid-handler-reopen-1', 'hash', 1, null );
		$conversations->transition( $conversation->id(), ConversationStatus::NEW, ConversationStatus::OPEN );
		$conversations->transition( $conversation->id(), ConversationStatus::OPEN, ConversationStatus::RESOLVED );

		$nonce                    = wp_create_nonce( ConversationActionHandler::NONCE_ACTION );
		$_POST['_wpnonce']        = $nonce;
		$_REQUEST['_wpnonce']     = $nonce;
		$_POST['op']              = 'reopen';
		$_POST['conversation_id'] = (string) $conversation->id();

		try {
			$handler->handle_request();
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}

		$this->assertSame( ConversationStatus::OPEN, $conversations->find( $conversation->id() )->status() );
	}

	public function test_reopen_never_reopens_an_archived_conversation(): void {
		$operator = self::factory()->user->create();
		$role     = get_role( 'subscriber' );
		$role->add_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		wp_set_current_user( $operator );

		list( $handler, , , $conversations ) = $this->fixture();
		$conversation                        = $conversations->create( 'uuid-handler-reopen-2', 'hash', 1, null );
		$conversations->transition( $conversation->id(), ConversationStatus::NEW, ConversationStatus::OPEN );
		$conversations->transition( $conversation->id(), ConversationStatus::OPEN, ConversationStatus::RESOLVED );
		$conversations->transition( $conversation->id(), ConversationStatus::RESOLVED, ConversationStatus::ARCHIVED );

		$nonce                    = wp_create_nonce( ConversationActionHandler::NONCE_ACTION );
		$_POST['_wpnonce']        = $nonce;
		$_REQUEST['_wpnonce']     = $nonce;
		$_POST['op']              = 'reopen';
		$_POST['conversation_id'] = (string) $conversation->id();

		try {
			$handler->handle_request();
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}

		$this->assertSame( ConversationStatus::ARCHIVED, $conversations->find( $conversation->id() )->status() );
	}

	public function test_add_note_creates_an_encrypted_note(): void {
		$operator = self::factory()->user->create();
		$role     = get_role( 'subscriber' );
		$role->add_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		wp_set_current_user( $operator );

		list( $handler, , , $conversations, $notes ) = $this->fixture();
		$conversation                                = $conversations->create( 'uuid-handler-note-1', 'hash', 1, null );

		$nonce                    = wp_create_nonce( ConversationActionHandler::NONCE_ACTION );
		$_POST['_wpnonce']        = $nonce;
		$_REQUEST['_wpnonce']     = $nonce;
		$_POST['op']              = 'add_note';
		$_POST['conversation_id'] = (string) $conversation->id();
		$_POST['body']            = 'Called the customer back.';

		try {
			$handler->handle_request();
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}

		$saved = $notes->for_conversation( $conversation->id() );
		$this->assertCount( 1, $saved );
		$this->assertSame( 'Called the customer back.', $notes->decrypt( $saved[0] ) );
		$this->assertSame( $operator, $saved[0]->operator_user_id() );
	}

	public function test_archive_revokes_secret_without_purging(): void {
		$operator = self::factory()->user->create();
		$role     = get_role( 'subscriber' );
		$role->add_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		wp_set_current_user( $operator );

		list( $handler, , , $conversations ) = $this->fixture();
		$conversation                        = $conversations->create( 'uuid-handler-archive-1', 'hash', 1, null );
		$conversations->transition( $conversation->id(), ConversationStatus::NEW, ConversationStatus::OPEN );

		$nonce                    = wp_create_nonce( ConversationActionHandler::NONCE_ACTION );
		$_POST['_wpnonce']        = $nonce;
		$_REQUEST['_wpnonce']     = $nonce;
		$_POST['op']              = 'archive';
		$_POST['conversation_id'] = (string) $conversation->id();

		try {
			$handler->handle_request();
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}

		$fresh = $conversations->find( $conversation->id() );
		$this->assertSame( ConversationStatus::ARCHIVED, $fresh->status() );
		$this->assertNull( $fresh->secret_hash() );
		$this->assertActionRecorded( 'conversation.archived' );
	}

	public function test_delete_permanently_purges_an_ineligible_archived_conversation_when_confirmed(): void {
		$operator = self::factory()->user->create();
		$role     = get_role( 'subscriber' );
		$role->add_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		wp_set_current_user( $operator );

		list( $handler, , , $conversations, , , $messages ) = $this->fixture();
		$conversation                                       = $conversations->create( 'uuid-handler-delete-1', 'hash', 1, null );
		$conversations->transition( $conversation->id(), ConversationStatus::NEW, ConversationStatus::OPEN );
		$conversations->transition( $conversation->id(), ConversationStatus::OPEN, ConversationStatus::RESOLVED );
		$conversations->transition( $conversation->id(), ConversationStatus::RESOLVED, ConversationStatus::ARCHIVED );
		$message = $messages->create( $conversation->id(), 'visitor', 'Hello' );

		$nonce                    = wp_create_nonce( ConversationActionHandler::NONCE_ACTION );
		$_POST['_wpnonce']        = $nonce;
		$_REQUEST['_wpnonce']     = $nonce;
		$_POST['op']              = 'delete_permanently';
		$_POST['confirm']         = '1';
		$_POST['conversation_id'] = (string) $conversation->id();

		try {
			$handler->handle_request();
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}

		$this->assertNull( $conversations->find( $conversation->id() ) );
		$this->assertNull( $messages->find( $message->id() ) );
		$this->assertActionRecorded( 'conversation.deleted_manually' );
		$this->assertStringContainsString( 'ut_notice=conversation_removed', (string) $handler->redirected_to );
	}

	public function test_delete_permanently_without_confirm_does_not_purge(): void {
		$operator = self::factory()->user->create();
		$role     = get_role( 'subscriber' );
		$role->add_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		wp_set_current_user( $operator );

		list( $handler, , , $conversations ) = $this->fixture();
		$conversation                        = $conversations->create( 'uuid-handler-delete-2', 'hash', 1, null );
		$conversations->transition( $conversation->id(), ConversationStatus::NEW, ConversationStatus::OPEN );
		$conversations->transition( $conversation->id(), ConversationStatus::OPEN, ConversationStatus::RESOLVED );
		$conversations->transition( $conversation->id(), ConversationStatus::RESOLVED, ConversationStatus::ARCHIVED );

		$nonce                    = wp_create_nonce( ConversationActionHandler::NONCE_ACTION );
		$_POST['_wpnonce']        = $nonce;
		$_REQUEST['_wpnonce']     = $nonce;
		$_POST['op']              = 'delete_permanently';
		$_POST['conversation_id'] = (string) $conversation->id();

		try {
			$handler->handle_request();
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}

		$this->assertNotNull( $conversations->find( $conversation->id() ) );
	}

	public function test_delete_permanently_never_deletes_a_non_archived_conversation(): void {
		$operator = self::factory()->user->create();
		$role     = get_role( 'subscriber' );
		$role->add_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		wp_set_current_user( $operator );

		list( $handler, , , $conversations ) = $this->fixture();
		$conversation                        = $conversations->create( 'uuid-handler-delete-3', 'hash', 1, null );
		$conversations->transition( $conversation->id(), ConversationStatus::NEW, ConversationStatus::OPEN );
		$conversations->transition( $conversation->id(), ConversationStatus::OPEN, ConversationStatus::RESOLVED );

		$nonce                    = wp_create_nonce( ConversationActionHandler::NONCE_ACTION );
		$_POST['_wpnonce']        = $nonce;
		$_REQUEST['_wpnonce']     = $nonce;
		$_POST['op']              = 'delete_permanently';
		$_POST['confirm']         = '1';
		$_POST['conversation_id'] = (string) $conversation->id();

		try {
			$handler->handle_request();
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}

		$this->assertNotNull( $conversations->find( $conversation->id() ) );
		$this->assertSame( ConversationStatus::RESOLVED, $conversations->find( $conversation->id() )->status() );
	}

	public function test_confirm_bulk_redirects_to_confirm_view_with_ids(): void {
		$operator = self::factory()->user->create();
		$role     = get_role( 'subscriber' );
		$role->add_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		wp_set_current_user( $operator );

		list( $handler, , , $conversations ) = $this->fixture();
		$a                                   = $conversations->create( 'uuid-bulk-confirm-a', 'hash', 1, null );
		$b                                   = $conversations->create( 'uuid-bulk-confirm-b', 'hash', 1, null );

		$nonce                     = wp_create_nonce( ConversationActionHandler::NONCE_ACTION );
		$_POST['_wpnonce']         = $nonce;
		$_REQUEST['_wpnonce']      = $nonce;
		$_POST['op']               = 'confirm_bulk_archive_and_delete';
		$_POST['conversation_ids'] = array( (string) $a->id(), (string) $b->id() );

		try {
			$handler->handle_request();
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}

		$this->assertStringContainsString( 'bulk_confirm=1', (string) $handler->redirected_to );
		$this->assertStringContainsString( (string) $a->id(), (string) $handler->redirected_to );
		$this->assertStringContainsString( (string) $b->id(), (string) $handler->redirected_to );
		$this->assertNotNull( $conversations->find( $a->id() ) );
	}

	public function test_bulk_archive_and_delete_without_confirm_does_nothing(): void {
		$operator = self::factory()->user->create();
		$role     = get_role( 'subscriber' );
		$role->add_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		wp_set_current_user( $operator );

		list( $handler, , , $conversations ) = $this->fixture();
		$conversation                        = $conversations->create( 'uuid-bulk-noconfirm', 'hash', 1, null );
		$conversations->transition( $conversation->id(), ConversationStatus::NEW, ConversationStatus::OPEN );

		$nonce                     = wp_create_nonce( ConversationActionHandler::NONCE_ACTION );
		$_POST['_wpnonce']         = $nonce;
		$_REQUEST['_wpnonce']      = $nonce;
		$_POST['op']               = 'bulk_archive_and_delete_permanently';
		$_POST['conversation_ids'] = array( (string) $conversation->id() );

		try {
			$handler->handle_request();
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}

		$this->assertNotNull( $conversations->find( $conversation->id() ) );
		$this->assertSame( ConversationStatus::OPEN, $conversations->find( $conversation->id() )->status() );
	}

	public function test_bulk_archive_and_delete_archives_then_purges_ineligible_rows(): void {
		$operator = self::factory()->user->create();
		$role     = get_role( 'subscriber' );
		$role->add_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		wp_set_current_user( $operator );

		list( $handler, , , $conversations, , , $messages ) = $this->fixture();
		$open = $conversations->create( 'uuid-bulk-open', 'hash', 1, null );
		$conversations->transition( $open->id(), ConversationStatus::NEW, ConversationStatus::OPEN );
		$archived = $conversations->create( 'uuid-bulk-archived', 'hash', 1, null );
		$conversations->transition( $archived->id(), ConversationStatus::NEW, ConversationStatus::OPEN );
		$conversations->transition( $archived->id(), ConversationStatus::OPEN, ConversationStatus::RESOLVED );
		$conversations->transition( $archived->id(), ConversationStatus::RESOLVED, ConversationStatus::ARCHIVED );
		$messages->create( $open->id(), 'visitor', 'Hi' );
		$messages->create( $archived->id(), 'visitor', 'Bye' );

		$nonce                     = wp_create_nonce( ConversationActionHandler::NONCE_ACTION );
		$_POST['_wpnonce']         = $nonce;
		$_REQUEST['_wpnonce']      = $nonce;
		$_POST['op']               = 'bulk_archive_and_delete_permanently';
		$_POST['confirm']          = '1';
		$_POST['conversation_ids'] = array( (string) $open->id(), (string) $archived->id() );

		try {
			$handler->handle_request();
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}

		$this->assertNull( $conversations->find( $open->id() ) );
		$this->assertNull( $conversations->find( $archived->id() ) );
		$this->assertStringContainsString( 'ut_notice=bulk_archive_delete', (string) $handler->redirected_to );
		$this->assertStringContainsString( 'bulk_removed=2', (string) $handler->redirected_to );
		$this->assertActionRecorded( 'conversation.archived' );
		$this->assertActionRecorded( 'conversation.deleted_manually' );
	}

	private function assertActionRecorded( string $action ): void {
		global $wpdb;
		$table   = $wpdb->prefix . 'universal_telegram_audit_log';
		$matches = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE action = %s", $action ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertGreaterThan( 0, (int) $matches, "Expected an audit entry for action {$action}" );
	}

	private function assertActionNotRecorded( string $action ): void {
		global $wpdb;
		$table   = $wpdb->prefix . 'universal_telegram_audit_log';
		$matches = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE action = %s", $action ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( 0, (int) $matches, "Expected no audit entry for action {$action}" );
	}
}
