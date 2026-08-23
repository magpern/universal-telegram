<?php
/**
 * Operator AI draft request handling.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\AI\Draft;

use UniversalTelegram\AI\Config\AIProviderRepository;
use UniversalTelegram\Administration\Hub\HubPage;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Queue\DispatchState;
use UniversalTelegram\Queue\JobEnvelope;

/**
 * The admin-post entry point for an operator's explicit "Request AI
 * draft" action (docs/adr/0028 decisions 1 and 5). Validates capability,
 * AI enablement, the conversation's own recorded acknowledgement, and
 * eligibility/idempotency/cooldown (delegated to
 * AiDraftRepository::request_draft()'s single transactional check) before
 * ever enqueueing an opaque job — the browser never contacts the provider,
 * and the queue payload never carries anything beyond ids (docs/adr/0006).
 *
 * Not declared final: tests override redirect_and_exit(), matching
 * SettingsPage's exact existing precedent.
 */
class DraftRequestHandler {

	public const ADMIN_POST_ACTION = 'universal_telegram_ai_draft_request';
	public const NONCE_ACTION      = 'universal_telegram_ai_draft_request';

	private const COOLDOWN_SECONDS = 30;

	/**
	 * Constructor.
	 *
	 * @param AiDraftRepository     $drafts          Transactional request/idempotency/cooldown enforcement.
	 * @param AIProviderRepository  $provider_config Reads enablement/model/provider.
	 * @param ConversationRepository $conversations  Reads the conversation's own acknowledgement state.
	 * @param Dispatcher            $dispatcher      Enqueues the opaque generation job.
	 */
	public function __construct(
		private readonly AiDraftRepository $drafts,
		private readonly AIProviderRepository $provider_config,
		private readonly ConversationRepository $conversations,
		private readonly Dispatcher $dispatcher
	) {}

	/**
	 * The admin-post handler.
	 */
	public function handle_request(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE_CONVERSATIONS ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'universal-telegram' ), '', 403 );
		}

		check_admin_referer( self::NONCE_ACTION );

		$conversation_id = isset( $_POST['conversation_id'] ) ? (int) $_POST['conversation_id'] : 0;

		$outcome = $this->request( $conversation_id, get_current_user_id() );

		$this->redirect_and_exit(
			add_query_arg(
				'ai_draft_notice',
				$outcome,
				admin_url( 'admin.php?page=' . HubPage::SLUG . '&tab=conversations&conversation_id=' . $conversation_id )
			)
		);
	}

	/**
	 * The full eligibility/idempotency/cooldown pipeline. Returns a fixed
	 * outcome code; never throws for an ordinary ineligibility reason.
	 *
	 * @param int $conversation_id      The conversation to draft a reply for.
	 * @param int $requested_by_user_id The requesting operator.
	 *
	 * @return string One of: 'created', 'existing_active', 'rejected_retained',
	 *                 'rejected_cooldown', 'not_found', 'ai_disabled', 'not_acknowledged', 'dispatch_failed'.
	 */
	public function request( int $conversation_id, int $requested_by_user_id ): string {
		$config = $this->provider_config->get();

		if ( null === $config || ! $config->is_ready() ) {
			return 'ai_disabled';
		}

		$conversation = $this->conversations->find( $conversation_id );

		if ( null === $conversation ) {
			return 'not_found';
		}

		if ( ! $conversation->is_ai_draft_eligible( $config->ack_policy_version() ) ) {
			return 'not_acknowledged';
		}

		$result = $this->drafts->request_draft(
			$conversation_id,
			$requested_by_user_id,
			$config->provider(),
			$config->model(),
			PromptBuilder::POLICY_VERSION,
			self::COOLDOWN_SECONDS
		);

		if ( 'created' !== $result['outcome'] ) {
			return $result['outcome'];
		}

		$envelope = new JobEnvelope(
			AIDraftGenerationHandler::JOB_TYPE,
			array(
				'draft_uuid'      => $result['draft_uuid'],
				'conversation_id' => $conversation_id,
				'bot_id'          => $conversation->bot_id(),
			),
			array(
				'draft_uuid'      => Classification::INTERNAL,
				'conversation_id' => Classification::INTERNAL,
				'bot_id'          => Classification::INTERNAL,
			)
		);

		$dispatch = $this->dispatcher->enqueue( $envelope );

		if ( DispatchState::SCHEDULED !== $dispatch->state() ) {
			$this->drafts->fail( $this->find_draft_id( $result['draft_uuid'] ), null, 'dispatch_failed' );
			return 'dispatch_failed';
		}

		$this->drafts->set_job_reference( $this->find_draft_id( $result['draft_uuid'] ), (string) $dispatch->action_id() );

		return 'created';
	}

	/**
	 * Resolves a draft_uuid to its primary key, for the rare dispatch-
	 * failure cleanup path only.
	 *
	 * @param string $draft_uuid The draft's opaque identifier.
	 */
	private function find_draft_id( string $draft_uuid ): int {
		$draft = $this->drafts->find_by_uuid( $draft_uuid );

		return null !== $draft ? $draft->id() : 0;
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
