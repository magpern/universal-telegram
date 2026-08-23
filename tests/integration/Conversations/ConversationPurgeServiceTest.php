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

	public function test_purge_handles_a_conversation_with_no_destination(): void {
		$conversation = $this->conversations->create( 'uuid-purge-no-destination', 'hash', 1, null );
		$message      = $this->messages->create( $conversation->id(), 'visitor', 'Hello there' );

		$this->purge_service->purge( $conversation->id(), null );

		$this->assertNull( $this->conversations->find( $conversation->id() ) );
		$this->assertNull( $this->messages->find( $message->id() ) );
	}
}
