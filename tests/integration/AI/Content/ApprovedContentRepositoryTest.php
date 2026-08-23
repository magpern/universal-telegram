<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\AI\Content;

use UniversalTelegram\AI\Content\ApprovedContentRepository;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\MessageRepository;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use WP_UnitTestCase;

/**
 * docs/adr/0028 decision 2: source-only, unconditional grounding — only
 * published, non-password-protected, explicitly-approved content is
 * eligible, revalidated against a captured revision marker; top_matches()
 * derives its query only from the conversation's own last visitor
 * message, never from a caller-supplied string.
 */
final class ApprovedContentRepositoryTest extends WP_UnitTestCase {

	private function message_repository(): MessageRepository {
		return new MessageRepository( new SchemaHealth(), new CredentialVault() );
	}

	private function repository(): ApprovedContentRepository {
		return new ApprovedContentRepository( $this->message_repository() );
	}

	private function conversation_id(): int {
		$conversations = new ConversationRepository( new SchemaHealth(), new CredentialVault(), new VisitorTokenGenerator() );

		return $conversations->create( wp_generate_uuid4(), 'hashed-secret', 1, null )->id();
	}

	public function test_approve_and_revoke_round_trip_for_a_published_post(): void {
		$post_id    = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$repository = $this->repository();

		$this->assertTrue( $repository->approve( $post_id ) );
		$this->assertTrue( $repository->is_currently_approved( $post_id ) );

		$repository->revoke( $post_id );
		$this->assertFalse( $repository->is_currently_approved( $post_id ) );
	}

	public function test_cannot_approve_unpublished_content(): void {
		$post_id    = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$repository = $this->repository();

		$this->assertFalse( $repository->approve( $post_id ) );
		$this->assertFalse( $repository->is_currently_approved( $post_id ) );
	}

	public function test_cannot_approve_password_protected_content(): void {
		$post_id    = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);
		$repository = $this->repository();

		$this->assertFalse( $repository->approve( $post_id ) );
	}

	public function test_editing_an_approved_post_excludes_it_until_re_approved(): void {
		$post_id    = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$repository = $this->repository();

		$this->assertTrue( $repository->approve( $post_id ) );
		$this->assertTrue( $repository->is_currently_approved( $post_id ) );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Edited content after approval.',
			)
		);

		$this->assertFalse( $repository->is_currently_approved( $post_id ), 'Editing a post must invalidate its prior approval.' );

		$this->assertTrue( $repository->approve( $post_id ), 'Re-approving after edit must succeed.' );
		$this->assertTrue( $repository->is_currently_approved( $post_id ) );
	}

	public function test_top_matches_derives_its_query_only_from_the_conversations_last_visitor_message(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Refund Policy',
				'post_content' => 'Our refund policy allows returns within thirty days of purchase for a full refund.',
			)
		);

		$repository = $this->repository();
		$this->assertTrue( $repository->approve( $post_id ) );

		$conversation_id = $this->conversation_id();
		$this->message_repository()->create( $conversation_id, 'visitor', 'How do refunds work for my order?' );

		$matches = $repository->top_matches( $conversation_id );

		$this->assertNotEmpty( $matches );
		$this->assertSame( $post_id, $matches[0]->post_id() );
	}

	public function test_top_matches_returns_empty_when_no_approved_source_matches(): void {
		$conversation_id = $this->conversation_id();
		$this->message_repository()->create( $conversation_id, 'visitor', 'Completely unrelated gibberish query xyzzyplugh' );

		$matches = $this->repository()->top_matches( $conversation_id );

		$this->assertSame( array(), $matches );
	}

	public function test_top_matches_returns_empty_when_conversation_has_no_visitor_message_yet(): void {
		$conversation_id = $this->conversation_id();

		$matches = $this->repository()->top_matches( $conversation_id );

		$this->assertSame( array(), $matches );
	}

	public function test_top_matches_excludes_a_stale_approval_edited_since(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Shipping takes five to seven business days for standard delivery.',
			)
		);

		$repository = $this->repository();
		$this->assertTrue( $repository->approve( $post_id ) );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Shipping takes five to seven business days for standard delivery, updated.',
			)
		);

		$conversation_id = $this->conversation_id();
		$this->message_repository()->create( $conversation_id, 'visitor', 'How long does shipping take?' );

		$matches = $repository->top_matches( $conversation_id );

		$this->assertSame( array(), $matches, 'A source edited since approval must be excluded until re-approved.' );
	}
}
