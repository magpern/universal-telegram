<?php
/**
 * Operator AI-summary request handling.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations\Intelligence;

use UniversalTelegram\AI\Config\AIProviderRepository;
use UniversalTelegram\Administration\Hub\HubPage;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Queue\DispatchState;
use UniversalTelegram\Queue\JobEnvelope;

/**
 * The admin-post entry point for an operator's explicit "Summarize with
 * AI" action (docs/plans/m11b-digests-and-operational-intelligence-plan-v1.md
 * §2.6/§3). Idempotency is SummaryAiRepository::request()'s own database
 * UNIQUE(summary_run_id) constraint — no row lock needed here. The browser
 * never contacts the provider, and the queue payload never carries
 * anything beyond ids.
 *
 * Not declared final: tests override redirect_and_exit(), matching
 * AI\Draft\DraftRequestHandler's exact existing precedent.
 */
class SummaryAiRequestHandler {

	public const ADMIN_POST_ACTION = 'universal_telegram_operational_summary_ai_request';
	public const NONCE_ACTION      = 'universal_telegram_operational_summary_ai_request';

	/**
	 * Constructor.
	 *
	 * @param SummaryAiRepository $drafts          Idempotent request enforcement (UNIQUE constraint).
	 * @param AIProviderRepository $provider_config Reads enablement/model/provider (M09's own config, reused).
	 * @param Dispatcher            $dispatcher      Enqueues the opaque generation job.
	 */
	public function __construct(
		private readonly SummaryAiRepository $drafts,
		private readonly AIProviderRepository $provider_config,
		private readonly Dispatcher $dispatcher
	) {}

	/**
	 * The admin-post handler.
	 */
	public function handle_request(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'universal-telegram' ), '', 403 );
		}

		check_admin_referer( self::NONCE_ACTION );

		$summary_run_id = isset( $_POST['summary_run_id'] ) ? (int) $_POST['summary_run_id'] : 0;

		$outcome = $this->request( $summary_run_id, get_current_user_id() );

		$this->redirect_and_exit(
			add_query_arg(
				'ai_summary_notice',
				$outcome,
				admin_url( 'admin.php?page=' . HubPage::SLUG . '&tab=rules' )
			)
		);
	}

	/**
	 * The full eligibility/idempotency pipeline. Returns a fixed outcome
	 * code; never throws for an ordinary ineligibility reason.
	 *
	 * @param int $summary_run_id       The operational_summary_runs row to summarize.
	 * @param int $requested_by_user_id The requesting operator.
	 *
	 * @return string One of: 'created', 'existing', 'ai_disabled', 'not_found', 'dispatch_failed'.
	 */
	public function request( int $summary_run_id, int $requested_by_user_id ): string {
		$config = $this->provider_config->get();

		if ( null === $config || ! $config->is_ready() ) {
			return 'ai_disabled';
		}

		if ( $summary_run_id <= 0 ) {
			return 'not_found';
		}

		$result = $this->drafts->request(
			$summary_run_id,
			$requested_by_user_id,
			$config->provider(),
			$config->model(),
			OperationalSummaryPromptBuilder::POLICY_VERSION
		);

		if ( 'created' !== $result['outcome'] ) {
			return $result['outcome'];
		}

		$envelope = new JobEnvelope(
			SummaryAiGenerationHandler::JOB_TYPE,
			array(
				'draft_uuid' => $result['draft_uuid'],
			),
			array(
				'draft_uuid' => Classification::INTERNAL,
			)
		);

		$dispatch = $this->dispatcher->enqueue( $envelope );

		if ( DispatchState::SCHEDULED !== $dispatch->state() ) {
			$draft = $this->drafts->find_by_uuid( (string) $result['draft_uuid'] );
			$this->drafts->fail( null !== $draft ? $draft->id() : 0, null, 'dispatch_failed' );
			return 'dispatch_failed';
		}

		return 'created';
	}

	/**
	 * Redirects and terminates the request. Overridden by tests.
	 *
	 * @param string $url The destination URL.
	 */
	protected function redirect_and_exit( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}
}
