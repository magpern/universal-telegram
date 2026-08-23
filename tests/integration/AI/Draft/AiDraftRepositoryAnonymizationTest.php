<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\AI\Draft;

use UniversalTelegram\AI\Draft\AiDraftRepository;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use WP_UnitTestCase;

/**
 * Docs/adr/0028 §4 retention table: operator account deletion anonymizes
 * requester/reviewer identity on every draft — draft content and status
 * are untouched, mirroring ConversationNote's identical precedent
 * (ADR-0026 decision 12b).
 */
final class AiDraftRepositoryAnonymizationTest extends WP_UnitTestCase {

	/**
	 * Explicit reset, not an assumption: the ai_drafts table is not
	 * reliably rolled back by WP_UnitTestCase's per-test transaction
	 * wrapping once a DDL statement elsewhere in the same run has forced
	 * an implicit commit.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->reset_ai_drafts();
	}

	protected function tearDown(): void {
		$this->reset_ai_drafts();
		parent::tearDown();
	}

	private function reset_ai_drafts(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}universal_telegram_ai_drafts" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function repository(): AiDraftRepository {
		return new AiDraftRepository( new SchemaHealth(), new CredentialVault() );
	}

	private function conversation_id(): int {
		$conversations = new ConversationRepository( new SchemaHealth(), new CredentialVault(), new VisitorTokenGenerator() );

		return $conversations->create( wp_generate_uuid4(), 'hashed-secret', 1, null )->id();
	}

	public function test_anonymize_operator_nulls_requested_and_reviewed_by_leaving_content_intact(): void {
		$repository = $this->repository();
		$draft      = $repository->create( $this->conversation_id(), 42, 'openai', 'gpt-4o-mini', 'v1' );
		$claim      = $repository->claim_for_generation( $draft->draft_uuid(), 90, 5 );
		$repository->complete_generation( $claim['draft_id'], $draft->draft_uuid(), $claim['lease_token'], 'body text', '[]', str_repeat( 'a', 64 ), 'v1' );
		$repository->mark_reviewed( $draft->id(), 42 );

		$repository->anonymize_operator( 42 );

		$anonymized = $repository->find( $draft->id() );
		$this->assertNull( $anonymized->requested_by_user_id() );
		$this->assertNull( $anonymized->reviewed_by_user_id() );
		$this->assertSame( 'reviewed', $anonymized->status() );

		$decrypted = $repository->decrypt_body( $anonymized );
		$this->assertSame( 'body text', $decrypted->plaintext() );
	}

	public function test_anonymize_operator_does_not_affect_a_different_operators_draft(): void {
		$repository = $this->repository();
		$draft      = $repository->create( $this->conversation_id(), 7, 'openai', 'gpt-4o-mini', 'v1' );

		$repository->anonymize_operator( 999 );

		$untouched = $repository->find( $draft->id() );
		$this->assertSame( 7, $untouched->requested_by_user_id() );
	}
}
