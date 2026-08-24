<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Conversations;

use UniversalTelegram\Administration\Conversations\ConversationDetailPage;
use UniversalTelegram\Conversations\ConversationNoteRepository;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Conversations\MessageRepository;
use UniversalTelegram\Conversations\OperatorIdentityRepository;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use WP_UnitTestCase;

final class ConversationDetailPageTest extends WP_UnitTestCase {

	private function page(): array {
		$schema_health = new SchemaHealth();
		$conversations = new ConversationRepository( $schema_health, new CredentialVault(), new VisitorTokenGenerator() );
		$messages      = new MessageRepository( $schema_health, new CredentialVault() );
		$notes         = new ConversationNoteRepository( $schema_health, new CredentialVault() );
		$identities    = new OperatorIdentityRepository( $schema_health );

		return array(
			new ConversationDetailPage( $conversations, $messages, $notes, $identities ),
			$conversations,
			$messages,
			$notes,
			$identities,
		);
	}

	public function test_unauthorized_user_is_denied(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		list( $page ) = $this->page();

		$this->expectException( \WPDieException::class );
		$page->render( 1 );
	}

	public function test_attributes_a_mapped_operators_reply_to_their_display_name_never_the_raw_telegram_id(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( $admin );

		list( $page, $conversations, $messages, $notes, $identities ) = $this->page();

		$operator = self::factory()->user->create( array( 'display_name' => 'Jamie Operator' ) );
		$identities->create( $operator, 999888777, 'jamie_tg', $admin );

		$conversation = $conversations->create( 'uuid-detail-1', 'hash', 1, null );
		$messages->create( $conversation->id(), 'operator', 'On it.', 'stored', null, null, 999888777 );

		ob_start();
		$page->render( $conversation->id() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Jamie Operator', $output );
		$this->assertStringNotContainsString( '999888777', $output );
		$this->assertStringNotContainsString( 'jamie_tg', $output );
	}

	public function test_an_unmapped_sender_renders_as_unmapped_never_the_raw_id(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( $admin );

		list( $page, $conversations, $messages ) = $this->page();

		$conversation = $conversations->create( 'uuid-detail-2', 'hash', 1, null );
		$messages->create( $conversation->id(), 'operator', 'Reply.', 'stored', null, null, 111222333 );

		ob_start();
		$page->render( $conversation->id() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Unmapped sender', $output );
		$this->assertStringNotContainsString( '111222333', $output );
	}

	public function test_a_note_with_a_null_author_renders_as_former_operator(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( $admin );

		list( $page, $conversations, $messages, $notes ) = $this->page();

		$conversation = $conversations->create( 'uuid-detail-3', 'hash', 1, null );
		$note         = $notes->create( $conversation->id(), 7, 'Some detail.' );
		$notes->anonymize_author( 7 );

		ob_start();
		$page->render( $conversation->id() );
		$output = ob_get_clean();

		$this->assertStringContainsString( '— former operator —', $output );
	}

	public function test_opening_the_detail_view_as_the_assigned_operator_marks_it_seen(): void {
		list( $page, $conversations, $messages ) = $this->page();

		$operator = self::factory()->user->create();
		$role     = get_role( 'subscriber' );
		$role->add_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		wp_set_current_user( $operator );

		$conversation = $conversations->create( 'uuid-detail-4', 'hash', 1, null );
		$conversations->assign( $conversation->id(), $operator );
		$message = $messages->create( $conversation->id(), 'visitor', 'Hello' );

		try {
			ob_start();
			$page->render( $conversation->id() );
			ob_get_clean();
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}

		$refreshed = $conversations->find( $conversation->id() );
		$this->assertSame( $message->id(), $refreshed->assignee_last_seen_message_id() );
	}

	public function test_opening_the_detail_view_as_a_different_user_never_marks_it_seen(): void {
		list( $page, $conversations, $messages ) = $this->page();

		$operator     = self::factory()->user->create();
		$other_viewer = self::factory()->user->create( array( 'role' => 'administrator' ) );
		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( $other_viewer );

		$conversation = $conversations->create( 'uuid-detail-5', 'hash', 1, null );
		$conversations->assign( $conversation->id(), $operator );
		$messages->create( $conversation->id(), 'visitor', 'Hello' );

		ob_start();
		$page->render( $conversation->id() );
		ob_get_clean();

		$refreshed = $conversations->find( $conversation->id() );
		$this->assertNull( $refreshed->assignee_last_seen_message_id() );
	}

	public function test_open_conversation_renders_archive_and_no_get_delete(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( $admin );

		list( $page, $conversations ) = $this->page();
		$conversation                 = $conversations->create( 'uuid-detail-actions', 'hash', 1, null );
		$conversations->transition( $conversation->id(), 'new', 'open' );

		ob_start();
		$page->render( $conversation->id() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="op" value="archive"', $output );
		$this->assertStringContainsString( 'Archive', $output );
		$this->assertStringNotContainsString( 'name="op" value="delete_permanently"', $output );
	}

	public function test_archived_confirm_delete_form_requires_second_post(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( $admin );

		list( $page, $conversations ) = $this->page();
		$conversation                 = $conversations->create( 'uuid-detail-confirm', 'hash', 1, null );
		$conversations->transition( $conversation->id(), 'new', 'open' );
		$conversations->transition( $conversation->id(), 'open', 'resolved' );
		$conversations->transition( $conversation->id(), 'resolved', 'archived' );

		$_GET['confirm_delete'] = '1';

		ob_start();
		$page->render( $conversation->id() );
		$output = ob_get_clean();

		unset( $_GET['confirm_delete'] );

		$this->assertStringContainsString( 'name="op" value="delete_permanently"', $output );
		$this->assertStringContainsString( 'name="confirm" value="1"', $output );
		$this->assertStringContainsString( 'This deletes the Telegram topic', $output );
	}
}
