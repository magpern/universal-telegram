<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Conversations;

use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Conversations\TopicCreationDispatcher;
use UniversalTelegram\Conversations\TopicCreationHandler;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Queue\DispatchState;
use UniversalTelegram\Queue\Dispatcher;
use WP_UnitTestCase;

final class TopicCreationDispatcherTest extends WP_UnitTestCase {

	public function test_maybe_create_enqueues_exactly_once_for_repeated_calls(): void {
		$schema_health = new SchemaHealth();
		$conversations = new ConversationRepository( $schema_health, new CredentialVault(), new VisitorTokenGenerator() );
		$dispatcher    = new TopicCreationDispatcher( $conversations, new Dispatcher( $schema_health ) );

		$conversation = $conversations->create( 'uuid-topic-1', 'hash', 1, null );

		$first  = $dispatcher->maybe_create( $conversation );
		$second = $dispatcher->maybe_create( $conversation );
		$third  = $dispatcher->maybe_create( $conversation );

		$this->assertNotNull( $first );
		$this->assertSame( DispatchState::SCHEDULED, $first->state() );
		$this->assertNull( $second );
		$this->assertNull( $third );

		$this->assertSame( 'pending', $conversations->find( $conversation->id() )->topic_creation_state() );

		$scheduled = as_get_scheduled_actions(
			array(
				'hook'  => \UniversalTelegram\Queue\WorkerRunner::HOOK,
				'group' => \UniversalTelegram\Queue\WorkerRunner::GROUP,
			)
		);

		$matching = array_filter(
			$scheduled,
			static function ( $action ) use ( $conversation ) {
				foreach ( $action->get_args() as $arg ) {
					if ( isset( $arg['job_type'], $arg['payload']['conversation_id'] )
						&& TopicCreationHandler::JOB_TYPE === $arg['job_type']
						&& $conversation->id() === $arg['payload']['conversation_id'] ) {
						return true;
					}
				}
				return false;
			}
		);

		$this->assertCount( 1, $matching );
	}

	public function test_maybe_create_is_a_noop_once_topic_creation_state_is_no_longer_none(): void {
		$schema_health = new SchemaHealth();
		$conversations = new ConversationRepository( $schema_health, new CredentialVault(), new VisitorTokenGenerator() );
		$dispatcher    = new TopicCreationDispatcher( $conversations, new Dispatcher( $schema_health ) );

		$conversation = $conversations->create( 'uuid-topic-2', 'hash', 1, null );
		$conversations->mark_topic_failed( $conversation->id() );

		$this->assertNull( $dispatcher->maybe_create( $conversation ) );
	}
}
