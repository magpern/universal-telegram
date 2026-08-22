<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Conversations;

use UniversalTelegram\Administration\Conversations\ConversationDetailPage;
use UniversalTelegram\Administration\Conversations\ConversationInboxPage;
use UniversalTelegram\Conversations\ConversationNoteRepository;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Conversations\MessageRepository;
use UniversalTelegram\Conversations\OperatorAvailabilityRepository;
use UniversalTelegram\Conversations\OperatorIdentityRepository;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use WP_UnitTestCase;

final class ConversationInboxPageTest extends WP_UnitTestCase {

	private function page(): array {
		$schema_health = new SchemaHealth();
		$conversations = new ConversationRepository( $schema_health, new CredentialVault(), new VisitorTokenGenerator() );
		$messages      = new MessageRepository( $schema_health, new CredentialVault() );
		$notes         = new ConversationNoteRepository( $schema_health, new CredentialVault() );
		$identities    = new OperatorIdentityRepository( $schema_health );
		$availability  = new OperatorAvailabilityRepository( $schema_health );
		$detail_page   = new ConversationDetailPage( $conversations, $messages, $notes, $identities );

		return array(
			new ConversationInboxPage( $conversations, $identities, $availability, $detail_page ),
			$conversations,
			$identities,
			$messages,
		);
	}

	protected function tearDown(): void {
		unset( $_GET['conversation_id'], $_GET['status'], $_GET['paged'] );
		parent::tearDown();
	}

	public function test_unauthorized_user_is_denied(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		list( $page ) = $this->page();

		$this->expectException( \WPDieException::class );
		$page->render_tab_content();
	}

	public function test_shows_an_unread_badge_for_the_current_operators_assigned_unread_conversation(): void {
		list( $page, $conversations, $identities, $messages ) = $this->page();

		$operator = self::factory()->user->create();
		$role     = get_role( 'subscriber' );
		$role->add_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		wp_set_current_user( $operator );

		$identities->create( $operator, 999888777, null, $operator );
		$conversation = $conversations->create( 'uuid-inbox-page-1', 'hash', 1, null );
		$conversations->assign( $conversation->id(), $operator );
		$messages->create( $conversation->id(), 'visitor', 'Hello' );

		try {
			ob_start();
			$page->render_tab_content();
			$output = ob_get_clean();
		} finally {
			$role->remove_cap( CapabilityRegistrar::MANAGE_CONVERSATIONS );
		}

		$this->assertStringContainsString( 'unread assigned conversation', $output );
	}

	public function test_never_renders_a_raw_telegram_id_or_username_anywhere(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( $admin );

		list( $page, $conversations, $identities, $messages ) = $this->page();

		$operator = self::factory()->user->create( array( 'display_name' => 'Robin Operator' ) );
		$identities->create( $operator, 999888777, 'robin_tg', $admin );
		$conversation = $conversations->create( 'uuid-inbox-page-2', 'hash', 1, null );
		$conversations->assign( $conversation->id(), $operator );

		ob_start();
		$page->render_tab_content();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( '999888777', $output );
		$this->assertStringNotContainsString( 'robin_tg', $output );
		$this->assertStringContainsString( 'Robin Operator', $output );
	}

	public function test_conversation_id_generated_urls_never_contain_a_raw_telegram_id(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( $admin );

		list( $page, $conversations, $identities ) = $this->page();
		$identities->create( $admin, 999888777, null, $admin );
		$conversations->create( 'uuid-inbox-page-3', 'hash', 1, null );

		ob_start();
		$page->render_tab_content();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'conversation_id=', $output );
		$this->assertStringNotContainsString( '999888777', $output );
	}
}
