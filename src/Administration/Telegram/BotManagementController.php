<?php
/**
 * Bot and destination management request handling.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Telegram;

use UniversalTelegram\Administration\Hub\HubPage;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Queue\JobEnvelope;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Configuration\InvalidDestinationException;
use UniversalTelegram\Telegram\Configuration\WebhookRegistrationCoordinator;
use UniversalTelegram\Telegram\Client\TelegramApiClient;
use UniversalTelegram\Telegram\Outbound\MessageDispatcher;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use UniversalTelegram\Queue\Dispatcher;

/**
 * Every action re-verifies both current_user_can(CapabilityRegistrar::MANAGE)
 * and its own nonce inside its own request handler — never relying solely
 * on menu-registration-time gating — following SelfTest::handle_request()'s
 * exact existing pattern. Token replacement validates the new token via a
 * synchronous getMe() call before committing it, leaving the old token
 * intact on a validation failure; BotProfileRepository itself performs no
 * remote validation of any kind.
 *
 * Not declared final: tests override redirect_and_exit() to avoid a real
 * exit call terminating the test process, matching SelfTest's exact
 * precedent. Production code never overrides it.
 */
class BotManagementController {

	public const ADMIN_POST_ACTION = 'universal_telegram_bot_management';
	public const NONCE_ACTION      = 'universal_telegram_bot_management';

	/**
	 * Constructor.
	 *
	 * @param BotProfileRepository           $bots         Bot profiles.
	 * @param DestinationRepository          $destinations Destinations.
	 * @param OutboundMessageRepository      $messages     Outbound messages.
	 * @param TelegramApiClient              $client       Used for synchronous getMe validation/testing.
	 * @param WebhookRegistrationCoordinator $coordinator  The registration/rotation protocol.
	 * @param MessageDispatcher              $message_dispatcher Queues a test message send.
	 * @param Dispatcher                     $dispatcher   Re-enqueues a requeued dead-lettered message.
	 */
	public function __construct(
		private readonly BotProfileRepository $bots,
		private readonly DestinationRepository $destinations,
		private readonly OutboundMessageRepository $messages,
		private readonly TelegramApiClient $client,
		private readonly WebhookRegistrationCoordinator $coordinator,
		private readonly MessageDispatcher $message_dispatcher,
		private readonly Dispatcher $dispatcher
	) {}

	/**
	 * The single admin-post request handler, dispatching on the 'op' field.
	 */
	public function handle_request(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'universal-telegram' ), '', 403 );
		}

		check_admin_referer( self::NONCE_ACTION );

		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';

		switch ( $op ) {
			case 'create_bot':
				$this->create_bot();
				break;
			case 'replace_token':
				$this->replace_token();
				break;
			case 'delete_bot':
				$this->delete_bot();
				break;
			case 'create_destination':
				$this->create_destination();
				break;
			case 'delete_destination':
				$this->delete_destination();
				break;
			case 'register_webhook':
				$this->run_coordinator_op( 'register' );
				break;
			case 'rotate_webhook':
				$this->run_coordinator_op( 'rotate' );
				break;
			case 'retry_pending_webhook':
				$this->run_coordinator_op( 'retry_pending' );
				break;
			case 'rollback_webhook':
				$this->run_coordinator_op( 'rollback' );
				break;
			case 'test_connection':
				$this->test_connection();
				break;
			case 'send_test_message':
				$this->send_test_message();
				break;
			case 'requeue_message':
				$this->requeue_message();
				break;
		}

		$redirect_url = admin_url( 'admin.php?page=' . HubPage::SLUG . '&tab=' . BotManagementPage::TAB_ID );

		// A bot created via the setup wizard's own create-bot form returns to
		// the wizard, continuing that same bot's checklist, instead of the
		// plain Bots tab (M06.1 corrective addendum: new-user guided setup).
		if ( 'create_bot' === $op
			&& isset( $_POST['from_wizard'] )
			&& '1' === sanitize_text_field( wp_unslash( $_POST['from_wizard'] ) ) ) {
			$redirect_url .= '&view=wizard&bot_id=latest';
		}

		$this->redirect_and_exit( $redirect_url );
	}

	/**
	 * Handles the create_bot operation.
	 */
	private function create_bot(): void {
		$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

		if ( '' === $name || '' === $token ) {
			return;
		}

		$validation = $this->client->get_me( $token );

		if ( ! $validation->ok() ) {
			return;
		}

		$bot = $this->bots->create( $name, $token );

		if ( null === $bot ) {
			return;
		}

		if ( isset( $validation->result()['id'] ) && is_int( $validation->result()['id'] ) ) {
			$username = isset( $validation->result()['username'] ) && is_string( $validation->result()['username'] ) ? $validation->result()['username'] : '';
			$this->bots->update_telegram_identity( $bot->id(), $validation->result()['id'], $username );
		}
	}

	/**
	 * Handles the replace_token operation.
	 */
	private function replace_token(): void {
		$bot_id    = isset( $_POST['bot_id'] ) ? (int) $_POST['bot_id'] : 0;
		$new_token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

		if ( 0 === $bot_id || '' === $new_token ) {
			return;
		}

		$validation = $this->client->get_me( $new_token );

		if ( ! $validation->ok() ) {
			// Validation failure: the old token is left completely intact.
			return;
		}

		$this->bots->replace_token( $bot_id, $new_token );
	}

	/**
	 * Handles the delete_bot operation.
	 */
	private function delete_bot(): void {
		$bot_id = isset( $_POST['bot_id'] ) ? (int) $_POST['bot_id'] : 0;

		if ( 0 === $bot_id ) {
			return;
		}

		$this->destinations->delete_for_bot( $bot_id );
		$this->bots->delete( $bot_id );
	}

	/**
	 * Handles the create_destination operation.
	 */
	private function create_destination(): void {
		$bot_id            = isset( $_POST['bot_id'] ) ? (int) $_POST['bot_id'] : 0;
		$kind_raw          = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
		$chat_id           = isset( $_POST['chat_id'] ) ? sanitize_text_field( wp_unslash( $_POST['chat_id'] ) ) : '';
		$label             = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		$message_thread_id = isset( $_POST['message_thread_id'] ) && '' !== $_POST['message_thread_id'] ? (int) $_POST['message_thread_id'] : null;

		$kind = DestinationKind::tryFrom( $kind_raw );

		if ( 0 === $bot_id || null === $kind || '' === $chat_id || '' === $label ) {
			return;
		}

		try {
			$this->destinations->create( $bot_id, $kind, $chat_id, $message_thread_id, $label );
		} catch ( InvalidDestinationException $exception ) {
			// A forum-topic ID was submitted for a non-supergroup kind; the
			// destination is simply not created.
			return;
		}
	}

	/**
	 * Handles the delete_destination operation.
	 */
	private function delete_destination(): void {
		$destination_id = isset( $_POST['destination_id'] ) ? (int) $_POST['destination_id'] : 0;

		if ( 0 === $destination_id ) {
			return;
		}

		$this->destinations->delete( $destination_id );
	}

	/**
	 * Handles register_webhook/rotate_webhook/retry_pending_webhook/rollback_webhook.
	 *
	 * @param 'register'|'rotate'|'retry_pending'|'rollback' $operation The coordinator method to invoke.
	 */
	private function run_coordinator_op( string $operation ): void {
		$bot_id = isset( $_POST['bot_id'] ) ? (int) $_POST['bot_id'] : 0;
		$bot    = 0 === $bot_id ? null : $this->bots->find( $bot_id );

		if ( null === $bot ) {
			return;
		}

		$this->coordinator->{$operation}( $bot );
	}

	/**
	 * Handles the test_connection operation.
	 */
	private function test_connection(): void {
		$bot_id = isset( $_POST['bot_id'] ) ? (int) $_POST['bot_id'] : 0;
		$bot    = 0 === $bot_id ? null : $this->bots->find( $bot_id );

		if ( null === $bot ) {
			return;
		}

		$token_result = $this->bots->decrypt_token( $bot );

		if ( \UniversalTelegram\Core\Security\CredentialState::AVAILABLE !== $token_result->state() || null === $token_result->plaintext() ) {
			return;
		}

		$result = $this->client->get_me( $token_result->plaintext() );

		if ( $result->ok() && isset( $result->result()['id'] ) && is_int( $result->result()['id'] ) ) {
			$username = isset( $result->result()['username'] ) && is_string( $result->result()['username'] ) ? $result->result()['username'] : '';
			$this->bots->update_telegram_identity( $bot->id(), $result->result()['id'], $username );
		}
	}

	/**
	 * Handles the send_test_message operation.
	 */
	private function send_test_message(): void {
		$bot_id         = isset( $_POST['bot_id'] ) ? (int) $_POST['bot_id'] : 0;
		$destination_id = isset( $_POST['destination_id'] ) ? (int) $_POST['destination_id'] : 0;

		if ( 0 === $bot_id || 0 === $destination_id ) {
			return;
		}

		$this->message_dispatcher->send( $bot_id, $destination_id, __( 'This is a test message from Telegram Operations Hub for WordPress.', 'universal-telegram' ) );
	}

	/**
	 * Handles the requeue_message operation.
	 */
	private function requeue_message(): void {
		$message_id = isset( $_POST['message_id'] ) ? (int) $_POST['message_id'] : 0;

		if ( 0 === $message_id ) {
			return;
		}

		$message = $this->messages->find( $message_id );

		if ( null === $message ) {
			return;
		}

		if ( ! $this->messages->requeue( $message_id ) ) {
			return;
		}

		$envelope = new JobEnvelope(
			MessageDispatcher::JOB_TYPE,
			array(
				'message_uuid'   => $message->message_uuid(),
				'bot_id'         => $message->bot_id(),
				'destination_id' => $message->destination_id(),
			),
			array(
				'message_uuid'   => Classification::INTERNAL,
				'bot_id'         => Classification::INTERNAL,
				'destination_id' => Classification::INTERNAL,
			)
		);

		$this->dispatcher->enqueue( $envelope );
	}

	/**
	 * Redirects and terminates the request. Overridable by tests, which
	 * cannot let a real exit call terminate the test process.
	 *
	 * @param string $url The URL to redirect to.
	 */
	protected function redirect_and_exit( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}
}
