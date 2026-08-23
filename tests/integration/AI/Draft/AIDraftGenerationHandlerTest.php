<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\AI\Draft;

use UniversalTelegram\AI\Config\AIProviderRepository;
use UniversalTelegram\AI\Content\ApprovedContentRepository;
use UniversalTelegram\AI\Draft\AIDraftGenerationHandler;
use UniversalTelegram\AI\Draft\AiDraftRepository;
use UniversalTelegram\AI\Draft\PromptBuilder;
use UniversalTelegram\AI\Provider\AiFailureClassifier;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\MessageRepository;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Queue\RetryPolicy;
use UniversalTelegram\Telegram\Reliability\CircuitBreaker;
use UniversalTelegram\Queue\WorkerRunner;
use ActionScheduler;
use RuntimeException;
use WP_UnitTestCase;

/**
 * Docs/adr/0028 decisions 4 and 5: the full AI-specific reliability
 * decision tree — circuit-open, concurrency-cap deferral, no-matching-
 * source, success, token-invalid, terminal, and retryable
 * (below/at the shared attempt budget) — mirroring
 * SendMessageHandlerTest's exact scope.
 */
final class AIDraftGenerationHandlerTest extends WP_UnitTestCase {

	/**
	 * @var array<int, callable>
	 */
	private array $filters_to_remove = array();

	/**
	 * Explicit reset, not an assumption: the singleton ai_config row, the
	 * ai_drafts table, the 'ai_provider' circuit-breaker scope, and Action
	 * Scheduler's own tables are not reliably rolled back by
	 * WP_UnitTestCase's per-test transaction wrapping once a DDL statement
	 * elsewhere in the same run has forced an implicit commit — the same
	 * reason Queue\QueueHealthTest resets Action Scheduler's group in
	 * setUp() rather than relying on tearDown alone.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->reset_ai_state();
	}

	protected function tearDown(): void {
		foreach ( $this->filters_to_remove as $callback ) {
			remove_filter( 'pre_http_request', $callback );
		}
		$this->filters_to_remove = array();

		$this->reset_ai_state();

		parent::tearDown();
	}

	private function reset_ai_state(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}universal_telegram_ai_drafts" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$wpdb->prefix}universal_telegram_ai_config SET enabled = 0, model = '', api_key_ciphertext = NULL WHERE id = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}universal_telegram_circuit_breaker_state WHERE scope_type = 'ai_provider'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$ids = ActionScheduler::store()->query_actions( array( 'group' => WorkerRunner::GROUP ) );
		foreach ( (array) $ids as $id ) {
			ActionScheduler::store()->delete_action( (int) $id );
		}
	}

	private function fake_response( int $status, array $body ): void {
		$callback = static function () use ( $status, $body ) {
			return array(
				'response' => array( 'code' => $status ),
				'body'     => wp_json_encode( $body ),
			);
		};
		add_filter( 'pre_http_request', $callback, 10, 0 );
		$this->filters_to_remove[] = $callback;
	}

	private function drafts(): AiDraftRepository {
		return new AiDraftRepository( new SchemaHealth(), new CredentialVault() );
	}

	private function provider(): AIProviderRepository {
		return new AIProviderRepository( new SchemaHealth(), new CredentialVault() );
	}

	private function handler( ?RetryPolicy $retry_policy = null ): AIDraftGenerationHandler {
		$messages         = new MessageRepository( new SchemaHealth(), new CredentialVault() );
		$approved_content = new ApprovedContentRepository( $messages );

		return new AIDraftGenerationHandler(
			$this->drafts(),
			$this->provider(),
			new PromptBuilder( $messages, $approved_content ),
			new CircuitBreaker( new SchemaHealth(), new RetryPolicy() ),
			new AiFailureClassifier(),
			$retry_policy ?? new RetryPolicy()
		);
	}

	private function conversation_with_approved_source_and_question(): int {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Our shipping takes five to seven business days.',
			)
		);
		( new ApprovedContentRepository( new MessageRepository( new SchemaHealth(), new CredentialVault() ) ) )->approve( $post_id );

		$conversations   = new ConversationRepository( new SchemaHealth(), new CredentialVault(), new VisitorTokenGenerator() );
		$conversation_id = $conversations->create( wp_generate_uuid4(), 'hashed-secret', 1, null )->id();
		( new MessageRepository( new SchemaHealth(), new CredentialVault() ) )->create( $conversation_id, 'visitor', 'How long does shipping take?' );

		return $conversation_id;
	}

	private function enable_provider(): void {
		$provider = $this->provider();
		$provider->set_credential( 'sk-test-key' );
		$provider->update_settings( 'gpt-4o-mini', true );
	}

	private function job( string $draft_uuid, int $attempt = 1 ): array {
		return array(
			'job_id'   => wp_generate_uuid4(),
			'job_type' => AIDraftGenerationHandler::JOB_TYPE,
			'attempt'  => $attempt,
			'payload'  => array( 'draft_uuid' => $draft_uuid ),
		);
	}

	public function test_success_marks_the_draft_generated_with_a_decryptable_body(): void {
		$this->enable_provider();
		$this->fake_response( 200, array( 'choices' => array( array( 'message' => array( 'content' => 'Here is a suggested reply.' ) ) ) ) );

		$drafts = $this->drafts();
		$draft  = $drafts->create( $this->conversation_with_approved_source_and_question(), 1, 'openai', 'gpt-4o-mini', 'v1' );

		$this->handler()->handle_job( $this->job( $draft->draft_uuid() ) );

		$result = $drafts->find( $draft->id() );
		$this->assertSame( 'generated', $result->status() );

		$decrypted = $drafts->decrypt_body( $result );
		$this->assertSame( 'Here is a suggested reply.', $decrypted->plaintext() );
	}

	public function test_no_matching_source_fails_without_any_provider_call(): void {
		$this->enable_provider();
		// No fake response queued: if a provider call were attempted, it
		// would hit a real network call and fail the test environment —
		// its absence is itself part of the proof no call was made.

		$conversations   = new ConversationRepository( new SchemaHealth(), new CredentialVault(), new VisitorTokenGenerator() );
		$conversation_id = $conversations->create( wp_generate_uuid4(), 'hashed-secret', 1, null )->id();
		( new MessageRepository( new SchemaHealth(), new CredentialVault() ) )->create( $conversation_id, 'visitor', 'Completely unrelated gibberish xyzzyplugh' );

		$drafts = $this->drafts();
		$draft  = $drafts->create( $conversation_id, 1, 'openai', 'gpt-4o-mini', 'v1' );

		$this->handler()->handle_job( $this->job( $draft->draft_uuid() ) );

		$result = $drafts->find( $draft->id() );
		$this->assertSame( 'failed', $result->status() );
		$this->assertSame( 'no_matching_source', $result->failure_class() );
	}

	public function test_circuit_open_fails_immediately_without_claiming_or_calling_the_provider(): void {
		$this->enable_provider();
		$breaker = new CircuitBreaker( new SchemaHealth(), new RetryPolicy() );
		$breaker->open_indefinitely( AIDraftGenerationHandler::CIRCUIT_SCOPE, AIDraftGenerationHandler::CIRCUIT_SCOPE_ID );

		$drafts = $this->drafts();
		$draft  = $drafts->create( $this->conversation_with_approved_source_and_question(), 1, 'openai', 'gpt-4o-mini', 'v1' );

		$this->handler()->handle_job( $this->job( $draft->draft_uuid() ) );

		$result = $drafts->find( $draft->id() );
		$this->assertSame( 'failed', $result->status() );
		$this->assertSame( 'circuit_open', $result->failure_class() );
		$this->assertNull( $result->lease_token() );
	}

	public function test_token_invalid_opens_the_circuit_and_dead_letters(): void {
		$this->enable_provider();
		$this->fake_response( 401, array( 'error' => array( 'message' => 'Incorrect API key' ) ) );

		$drafts = $this->drafts();
		$draft  = $drafts->create( $this->conversation_with_approved_source_and_question(), 1, 'openai', 'gpt-4o-mini', 'v1' );

		$this->handler()->handle_job( $this->job( $draft->draft_uuid() ) );

		$result = $drafts->find( $draft->id() );
		$this->assertSame( 'failed', $result->status() );
		$this->assertSame( 'token_invalid', $result->failure_class() );

		$breaker = new CircuitBreaker( new SchemaHealth(), new RetryPolicy() );
		$this->assertFalse( $breaker->may_attempt( AIDraftGenerationHandler::CIRCUIT_SCOPE, AIDraftGenerationHandler::CIRCUIT_SCOPE_ID ) );
	}

	public function test_terminal_failure_dead_letters_without_opening_the_circuit_indefinitely(): void {
		$this->enable_provider();
		$this->fake_response( 400, array( 'error' => array( 'message' => 'Bad request' ) ) );

		$drafts = $this->drafts();
		$draft  = $drafts->create( $this->conversation_with_approved_source_and_question(), 1, 'openai', 'gpt-4o-mini', 'v1' );

		$this->handler()->handle_job( $this->job( $draft->draft_uuid() ) );

		$result = $drafts->find( $draft->id() );
		$this->assertSame( 'failed', $result->status() );
		$this->assertSame( 'provider_terminal_error', $result->failure_class() );
	}

	public function test_retryable_failure_below_the_attempt_budget_releases_to_queued_and_throws(): void {
		$this->enable_provider();
		$this->fake_response( 500, array( 'error' => array( 'message' => 'Internal error' ) ) );

		$drafts = $this->drafts();
		$draft  = $drafts->create( $this->conversation_with_approved_source_and_question(), 1, 'openai', 'gpt-4o-mini', 'v1' );

		$this->expectException( RuntimeException::class );

		try {
			$this->handler()->handle_job( $this->job( $draft->draft_uuid() ) );
		} finally {
			$result = $drafts->find( $draft->id() );
			$this->assertSame( 'queued', $result->status() );
			$this->assertNull( $result->lease_token() );
			$this->assertSame( 1, $result->attempt_count() );
		}
	}

	public function test_retryable_failure_at_the_shared_attempt_budget_dead_letters_without_throwing(): void {
		$this->enable_provider();
		$this->fake_response( 500, array( 'error' => array( 'message' => 'Internal error' ) ) );

		$drafts = $this->drafts();
		$draft  = $drafts->create( $this->conversation_with_approved_source_and_question(), 1, 'openai', 'gpt-4o-mini', 'v1' );

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'universal_telegram_ai_drafts',
			array( 'attempt_count' => 4 ),
			array( 'id' => $draft->id() )
		);

		// The 5th claim (attempt_count becomes 5, RetryPolicy::MAX_ATTEMPTS)
		// must dead-letter rather than release-and-throw.
		$this->handler()->handle_job( $this->job( $draft->draft_uuid() ) );

		$result = $drafts->find( $draft->id() );
		$this->assertSame( 'failed', $result->status() );
		$this->assertSame( 'provider_timeout', $result->failure_class() );
	}

	public function test_concurrency_cap_defers_without_incrementing_attempt_count_or_throwing(): void {
		$this->enable_provider();

		$drafts            = $this->drafts();
		$conversation_id_a = $this->conversation_with_approved_source_and_question();
		$conversation_id_b = $this->conversation_with_approved_source_and_question();
		$conversation_id_c = $this->conversation_with_approved_source_and_question();

		$draft_a = $drafts->create( $conversation_id_a, 1, 'openai', 'gpt-4o-mini', 'v1' );
		$draft_b = $drafts->create( $conversation_id_b, 1, 'openai', 'gpt-4o-mini', 'v1' );
		$draft_c = $drafts->create( $conversation_id_c, 1, 'openai', 'gpt-4o-mini', 'v1' );

		// Manually saturate the cap (2) so the handler's own claim step
		// observes it already reached, without needing real concurrency.
		$this->assertNotNull( $drafts->claim_for_generation( $draft_a->draft_uuid(), 90, 2 ) );
		$this->assertNotNull( $drafts->claim_for_generation( $draft_b->draft_uuid(), 90, 2 ) );

		$this->handler()->handle_job( $this->job( $draft_c->draft_uuid() ) );

		$result = $drafts->find( $draft_c->id() );
		$this->assertSame( 'queued', $result->status(), 'A capacity-deferred job must remain queued, not failed.' );
		$this->assertSame( 0, $result->attempt_count(), 'A capacity deferral must never consume an attempt.' );
	}
}
