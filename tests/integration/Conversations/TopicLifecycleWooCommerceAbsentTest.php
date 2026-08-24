<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Conversations;

use UniversalTelegram\Conversations\ConversationPurgeService;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\ConversationTopicEligibility;
use UniversalTelegram\Conversations\MessageRepository;
use UniversalTelegram\Conversations\TopicDeletionDispatcher;
use UniversalTelegram\Conversations\TopicDeletionHandler;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Queue\RetryPolicy;
use UniversalTelegram\Telegram\Client\TelegramApiClient;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use WP_UnitTestCase;

/**
 * M07.1 classes must construct without WooCommerce loaded.
 */
final class TopicLifecycleWooCommerceAbsentTest extends WP_UnitTestCase {

	public function test_topic_lifecycle_classes_construct_without_woocommerce(): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class, false ) ) {
			$this->markTestSkipped( 'WooCommerce is loaded on this matrix leg; WP-only absence is covered there.' );
		}

		$this->assertFalse( class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class, false ) );

		$schema        = new SchemaHealth();
		$vault         = new CredentialVault();
		$conversations = new ConversationRepository( $schema, $vault, new VisitorTokenGenerator() );
		$messages      = new MessageRepository( $schema, $vault );
		$destinations  = new DestinationRepository( $schema );
		$bots          = new BotProfileRepository( $schema, $vault );
		$eligibility   = new ConversationTopicEligibility( $conversations, $destinations );
		$purge         = new ConversationPurgeService( $conversations, $messages, $destinations );
		$dispatcher    = new TopicDeletionDispatcher( $conversations, new Dispatcher( $schema ) );
		$handler       = new TopicDeletionHandler(
			$conversations,
			$bots,
			$eligibility,
			$purge,
			new TelegramApiClient(),
			new RetryPolicy()
		);

		$this->assertInstanceOf( ConversationTopicEligibility::class, $eligibility );
		$this->assertInstanceOf( TopicDeletionDispatcher::class, $dispatcher );
		$this->assertInstanceOf( TopicDeletionHandler::class, $handler );
		$this->assertSame( TopicDeletionHandler::JOB_TYPE, 'conversation_delete_topic' );
	}
}
