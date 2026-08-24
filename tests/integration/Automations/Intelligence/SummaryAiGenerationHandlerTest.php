<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Automations\Intelligence;

use ActionScheduler;
use UniversalTelegram\AI\Config\AIProviderRepository;
use UniversalTelegram\AI\Draft\AIDraftGenerationHandler;
use UniversalTelegram\AI\Provider\AiFailureClassifier;
use UniversalTelegram\AI\Provider\ProviderConcurrencyGate;
use UniversalTelegram\Automations\Intelligence\OperationalSummaryPromptBuilder;
use UniversalTelegram\Automations\Intelligence\OperationalSummaryRepository;
use UniversalTelegram\Automations\Intelligence\SummaryAiGenerationHandler;
use UniversalTelegram\Automations\Intelligence\SummaryAiLeaseSweep;
use UniversalTelegram\Automations\Intelligence\SummaryAiRepository;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Queue\RetryPolicy;
use UniversalTelegram\Queue\WorkerRunner;
use UniversalTelegram\Telegram\Reliability\CircuitBreaker;
use WP_UnitTestCase;

final class SummaryAiGenerationHandlerTest extends WP_UnitTestCase {

	/**
	 * @var array<int, callable>
	 */
	private array $filters_to_remove = array();

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
		$wpdb->query( "DELETE FROM {$wpdb->prefix}universal_telegram_operational_summary_ai_drafts" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}universal_telegram_ai_drafts" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}universal_telegram_operational_summary_runs" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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

	private function drafts(): SummaryAiRepository {
		return new SummaryAiRepository( new SchemaHealth(), new CredentialVault() );
	}

	private function summaries(): OperationalSummaryRepository {
		return new OperationalSummaryRepository( new SchemaHealth() );
	}

	private function provider(): AIProviderRepository {
		return new AIProviderRepository( new SchemaHealth(), new CredentialVault() );
	}

	private function enable_provider(): void {
		$provider = $this->provider();
		$provider->set_credential( 'sk-test-key' );
		$provider->update_settings( 'gpt-4o-mini', true );
	}

	private function handler(): SummaryAiGenerationHandler {
		return new SummaryAiGenerationHandler(
			$this->drafts(),
			$this->provider(),
			new OperationalSummaryPromptBuilder(),
			$this->summaries(),
			new CircuitBreaker( new SchemaHealth(), new RetryPolicy() ),
			new AiFailureClassifier(),
			new RetryPolicy(),
			new ProviderConcurrencyGate( new SchemaHealth() )
		);
	}

	private function job( string $draft_uuid ): array {
		return array(
			'job_id'   => wp_generate_uuid4(),
			'job_type' => SummaryAiGenerationHandler::JOB_TYPE,
			'attempt'  => 1,
			'payload'  => array( 'draft_uuid' => $draft_uuid ),
		);
	}

	private function summary_run_id(): int {
		$row = $this->summaries()->create_or_get_for_date( gmdate( 'Y-m-d' ), gmdate( 'Y-m-d 00:00:00' ), gmdate( 'Y-m-d H:i:s' ) );

		return (int) $row['id'];
	}

	public function test_success_marks_the_draft_generated_with_a_decryptable_body(): void {
		$this->enable_provider();
		$this->fake_response( 200, array( 'choices' => array( array( 'message' => array( 'content' => 'Here is a summary.' ) ) ) ) );

		$drafts  = $this->drafts();
		$request = $drafts->request( $this->summary_run_id(), 1, 'openai', 'gpt-4o-mini', OperationalSummaryPromptBuilder::POLICY_VERSION );
		$draft   = $drafts->find_by_uuid( (string) $request['draft_uuid'] );

		$this->handler()->handle_job( $this->job( $draft->draft_uuid() ) );

		$result = $drafts->find( $draft->id() );
		$this->assertSame( 'generated', $result->status() );

		$decrypted = $drafts->decrypt_body( $result );
		$this->assertSame( 'Here is a summary.', $decrypted->plaintext() );
	}

	public function test_circuit_open_fails_immediately_without_claiming_or_calling_the_provider(): void {
		$this->enable_provider();
		$breaker = new CircuitBreaker( new SchemaHealth(), new RetryPolicy() );
		$breaker->open_indefinitely( AIDraftGenerationHandler::CIRCUIT_SCOPE, AIDraftGenerationHandler::CIRCUIT_SCOPE_ID );

		$drafts  = $this->drafts();
		$request = $drafts->request( $this->summary_run_id(), 1, 'openai', 'gpt-4o-mini', OperationalSummaryPromptBuilder::POLICY_VERSION );
		$draft   = $drafts->find_by_uuid( (string) $request['draft_uuid'] );

		$this->handler()->handle_job( $this->job( $draft->draft_uuid() ) );

		$result = $drafts->find( $draft->id() );
		$this->assertSame( 'failed', $result->status() );
		$this->assertSame( 'circuit_open', $result->failure_class() );
		$this->assertNull( $result->lease_token() );
	}

	public function test_provider_disabled_fails_after_claim(): void {
		// Provider is left disabled (default).
		$drafts  = $this->drafts();
		$request = $drafts->request( $this->summary_run_id(), 1, 'openai', 'gpt-4o-mini', OperationalSummaryPromptBuilder::POLICY_VERSION );
		$draft   = $drafts->find_by_uuid( (string) $request['draft_uuid'] );

		$this->handler()->handle_job( $this->job( $draft->draft_uuid() ) );

		$result = $drafts->find( $draft->id() );
		$this->assertSame( 'failed', $result->status() );
		$this->assertSame( 'provider_disabled', $result->failure_class() );
	}

	public function test_token_invalid_opens_the_shared_circuit_scope(): void {
		$this->enable_provider();
		$this->fake_response( 401, array( 'error' => array( 'message' => 'Incorrect API key' ) ) );

		$drafts  = $this->drafts();
		$request = $drafts->request( $this->summary_run_id(), 1, 'openai', 'gpt-4o-mini', OperationalSummaryPromptBuilder::POLICY_VERSION );
		$draft   = $drafts->find_by_uuid( (string) $request['draft_uuid'] );

		$this->handler()->handle_job( $this->job( $draft->draft_uuid() ) );

		$result = $drafts->find( $draft->id() );
		$this->assertSame( 'failed', $result->status() );
		$this->assertSame( 'token_invalid', $result->failure_class() );

		// The circuit scope is shared with M09's AI draft assistant — this
		// assertion is the whole point of the shared scope.
		$breaker = new CircuitBreaker( new SchemaHealth(), new RetryPolicy() );
		$this->assertFalse( $breaker->may_attempt( AIDraftGenerationHandler::CIRCUIT_SCOPE, AIDraftGenerationHandler::CIRCUIT_SCOPE_ID ) );
	}

	public function test_stale_lease_is_reclaimed_by_the_sweep_not_the_mere_passage_of_time(): void {
		$this->enable_provider();

		$drafts  = $this->drafts();
		$request = $drafts->request( $this->summary_run_id(), 1, 'openai', 'gpt-4o-mini', OperationalSummaryPromptBuilder::POLICY_VERSION );
		$claim   = $drafts->claim_candidate_row( (string) $request['draft_uuid'], 90 );
		$this->assertNotNull( $claim );

		// Force the lease into the past to simulate a crashed worker.
		global $wpdb;
		$table = $wpdb->prefix . 'universal_telegram_operational_summary_ai_drafts';
		$wpdb->update( $table, array( 'generation_lease_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 10 ) ), array( 'id' => $claim['draft_id'] ) );

		$sweep = new SummaryAiLeaseSweep( $drafts );
		$sweep->run();

		$result = $drafts->find( $claim['draft_id'] );
		$this->assertSame( 'queued', $result->status() );
		$this->assertNull( $result->lease_token() );
	}
}
