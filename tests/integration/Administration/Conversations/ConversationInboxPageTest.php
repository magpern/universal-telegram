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

	/**
	 * Explicit reset, not an assumption: universal_telegram_conversations
	 * rows are not reliably rolled back by WP_UnitTestCase's per-test
	 * transaction wrapping once a DDL statement elsewhere in the same run
	 * (e.g. a Migrator test) has forced an implicit commit — the same
	 * documented root cause already noted elsewhere in this suite for
	 * plain option writes and Action Scheduler rows. This class's own
	 * assertions depend on its own fixture conversations appearing within
	 * the inbox's default (unpaginated) result set, which an unrelated
	 * test's accumulated, never-rolled-back rows can otherwise crowd out.
	 */
	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}universal_telegram_conversation_messages" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}universal_telegram_conversations" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

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
		unset(
			$_GET['conversation_id'],
			$_GET['status'],
			$_GET['paged'],
			$_GET['q'],
			$_GET['bot_id'],
			$_GET['assigned_operator_id'],
			$_GET['created_from'],
			$_GET['created_to'],
			$_GET['bulk_confirm'],
			$_GET['ids'],
			$_GET['ut_notice'],
			$_GET['bulk_queued'],
			$_GET['bulk_removed'],
			$_GET['bulk_skipped']
		);
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

	public function test_search_filters_by_conversation_uuid_prefix(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( $admin );

		list( $page, $conversations ) = $this->page();
		$conversations->create( 'search-page-target-abc', 'hash', 1, null );
		$conversations->create( 'other-page-conversation', 'hash', 1, null );

		$_GET['q'] = 'search-page-target';

		ob_start();
		$page->render_tab_content();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'search-p', $output );
		$this->assertStringNotContainsString( 'other-pa', $output );
	}

	public function test_the_filter_form_never_offers_a_telegram_id_or_username_field(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( $admin );

		list( $page ) = $this->page();

		ob_start();
		$page->render_tab_content();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'telegram_user_id', $output );
		$this->assertStringNotContainsString( 'telegram_username', $output );
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

	public function test_inbox_list_offers_bulk_archive_and_delete_controls(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( $admin );

		list( $page, $conversations ) = $this->page();
		$conversations->create( 'uuid-inbox-bulk-1', 'hash', 1, null );

		ob_start();
		$page->render_tab_content();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="op" value="confirm_bulk_archive_and_delete"', $output );
		$this->assertStringContainsString( 'name="conversation_ids[]"', $output );
		$this->assertStringContainsString( 'Archive and delete permanently…', $output );
	}

	public function test_bulk_confirm_view_requires_second_post(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		( new CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( $admin );

		list( $page, $conversations ) = $this->page();
		$conversation                 = $conversations->create( 'uuid-inbox-bulk-confirm', 'hash', 1, null );

		$_GET['bulk_confirm'] = '1';
		$_GET['ids']          = (string) $conversation->id();

		ob_start();
		$page->render_tab_content();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="op" value="bulk_archive_and_delete_permanently"', $output );
		$this->assertStringContainsString( 'name="confirm" value="1"', $output );
		$this->assertStringContainsString( 'Confirm archive and delete permanently', $output );
		$this->assertStringContainsString( 'cannot be undone', $output );
	}
}
