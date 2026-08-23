<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Conversations;

use UniversalTelegram\Conversations\ConversationPurgeService;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Conversations\MessageRepository;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use WP_UnitTestCase;

final class ConversationPurgeServiceTest extends WP_UnitTestCase {

	private ConversationRepository $conversations;
	private MessageRepository $messages;
	private DestinationRepository $destinations;
	private ConversationPurgeService $purge_service;

	protected function setUp(): void {
		parent::setUp();

		$schema_health       = new SchemaHealth();
		$this->conversations = new ConversationRepository( $schema_health, new CredentialVault(), new VisitorTokenGenerator() );
		$this->messages      = new MessageRepository( $schema_health, new CredentialVault() );
		$this->destinations  = new DestinationRepository( $schema_health );
		$this->purge_service = new ConversationPurgeService( $this->conversations, $this->messages, $this->destinations );
	}

	public function test_purge_deletes_the_conversation_its_messages_and_its_destination(): void {
		$conversation = $this->conversations->create( 'uuid-purge-with-destination', 'hash', 1, null );
		$destination  = $this->destinations->create( 1, DestinationKind::SUPERGROUP, '-100123', 55, 'Topic' );
		$this->conversations->set_destination( $conversation->id(), $destination->id() );
		$message = $this->messages->create( $conversation->id(), 'visitor', 'Hello there' );

		$this->purge_service->purge( $conversation->id(), $destination->id() );

		$this->assertNull( $this->conversations->find( $conversation->id() ) );
		$this->assertNull( $this->messages->find( $message->id() ) );
		$this->assertNull( $this->destinations->find( $destination->id() ) );
	}

	/**
	 * docs/adr/0028 §4 retention table: an AI draft, whatever its status,
	 * is never left orphaned against a deleted conversation.
	 */
	public function test_purge_deletes_ai_draft_rows_for_the_conversation(): void {
		global $wpdb;

		$conversation = $this->conversations->create( wp_generate_uuid4(), 'hash', 1, null );

		$table = $wpdb->prefix . 'universal_telegram_ai_drafts';
		$wpdb->insert(
			$table,
			array(
				'draft_uuid'             => wp_generate_uuid4(),
				'conversation_id'        => $conversation->id(),
				'status'                 => 'generated',
				'provider'               => 'openai',
				'model'                  => 'gpt-4o-mini',
				'prompt_policy_version'  => 'v1',
				'requested_by_user_id'   => 1,
				'created_at'             => current_time( 'mysql', true ),
				'updated_at'             => current_time( 'mysql', true ),
			)
		);

		$this->purge_service->purge( $conversation->id(), null );

		$remaining = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE conversation_id = %d", $conversation->id() ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$this->assertSame( 0, $remaining );
	}

	public function test_purge_handles_a_conversation_with_no_destination(): void {
		$conversation = $this->conversations->create( 'uuid-purge-no-destination', 'hash', 1, null );
		$message      = $this->messages->create( $conversation->id(), 'visitor', 'Hello there' );

		$this->purge_service->purge( $conversation->id(), null );

		$this->assertNull( $this->conversations->find( $conversation->id() ) );
		$this->assertNull( $this->messages->find( $message->id() ) );
	}
}
