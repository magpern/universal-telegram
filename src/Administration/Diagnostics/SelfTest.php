<?php
/**
 * Bounded diagnostic self-test.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Diagnostics;

use RuntimeException;
use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Queue\JobEnvelope;

/**
 * Deliberately bounded and safe enough to ship in the plugin's own
 * distributable package. Available only when WP_DEBUG is true, to a user
 * holding universal_telegram_manage, with a valid nonce, and only while
 * the schema is available. Nothing about its own behaviour is influenced
 * by any input beyond a single integer from one to five (docs/adr — see
 * the M00 plan section 4.9).
 *
 * Not declared final: tests/integration/Administration/Diagnostics/SelfTestTest
 * overrides is_debug_mode() to deterministically exercise the
 * WP_DEBUG-false path — the shared WP test environment defines WP_DEBUG
 * as true globally, and PHP constants cannot be redefined within one
 * process. Production code never overrides it.
 */
class SelfTest {

	public const JOB_TYPE = 'diagnostics_self_test';

	private const NONCE_ACTION      = 'universal_telegram_diag_self_test';
	private const ADMIN_POST_ACTION = 'universal_telegram_diag_self_test';
	private const SYNTHETIC_SECRET  = 'UT-SELFTEST-4f8b2c91-4d3a-4b7e-9c1a-do-not-use';

	/**
	 * Checked before every use.
	 *
	 * @var SchemaHealth
	 */
	private SchemaHealth $schema_health;

	/**
	 * Dispatches the self-test job.
	 *
	 * @var Dispatcher
	 */
	private Dispatcher $dispatcher;

	/**
	 * Encrypts the synthetic secret.
	 *
	 * @var CredentialVault
	 */
	private CredentialVault $credential_vault;

	/**
	 * Records the ciphertext-only proof.
	 *
	 * @var AuditLogger
	 */
	private AuditLogger $audit_logger;

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth    $schema_health    Checked before every use.
	 * @param Dispatcher      $dispatcher       Dispatches the self-test job.
	 * @param CredentialVault $credential_vault Encrypts the synthetic secret.
	 * @param AuditLogger     $audit_logger     Records the ciphertext-only proof.
	 */
	public function __construct(
		SchemaHealth $schema_health,
		Dispatcher $dispatcher,
		CredentialVault $credential_vault,
		AuditLogger $audit_logger
	) {
		$this->schema_health    = $schema_health;
		$this->dispatcher       = $dispatcher;
		$this->credential_vault = $credential_vault;
		$this->audit_logger     = $audit_logger;
	}

	/**
	 * The admin-post action name this handler is registered against.
	 *
	 * @return string
	 */
	public function admin_post_action(): string {
		return self::ADMIN_POST_ACTION;
	}

	/**
	 * Whether the control should be rendered at all. Absent, not merely
	 * disabled, whenever any condition is not met.
	 *
	 * @return bool
	 */
	public function should_render(): bool {
		return $this->is_debug_mode()
			&& current_user_can( CapabilityRegistrar::MANAGE )
			&& $this->schema_health->is_available();
	}

	/**
	 * Whether WP_DEBUG is on. Overridable by tests; see the class docblock.
	 *
	 * @return bool
	 */
	protected function is_debug_mode(): bool {
		return defined( 'WP_DEBUG' ) && WP_DEBUG;
	}

	/**
	 * Renders the control, if and only if should_render() is true.
	 */
	public function render_control(): void {
		if ( ! $this->should_render() ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Diagnostic self-test', 'universal-telegram' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ADMIN_POST_ACTION ) . '" />';
		wp_nonce_field( self::NONCE_ACTION );

		echo '<p><label for="universal_telegram_fail_count">' .
			esc_html__( 'Fail this many attempts before succeeding (5 fails every permitted attempt):', 'universal-telegram' ) .
			'</label> ';
		echo '<select name="fail_count" id="universal_telegram_fail_count">';
		for ( $i = 1; $i <= 5; $i++ ) {
			printf( '<option value="%1$s">%1$s</option>', esc_attr( (string) $i ) );
		}
		echo '</select></p>';

		submit_button( __( 'Run self-test', 'universal-telegram' ) );
		echo '</form>';
	}

	/**
	 * The admin-post request handler, triggered by submitting the control.
	 */
	public function handle_request(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'universal-telegram' ), '', 403 );
		}

		check_admin_referer( self::NONCE_ACTION );

		if ( ! $this->should_render() ) {
			wp_die( esc_html__( 'The diagnostic self-test is not currently available.', 'universal-telegram' ), '', 403 );
		}

		$fail_count = isset( $_POST['fail_count'] ) ? (int) $_POST['fail_count'] : 5;

		if ( $fail_count < 1 || $fail_count > 5 ) {
			$fail_count = 5;
		}

		$ciphertext = $this->credential_vault->encrypt( self::SYNTHETIC_SECRET, self::NONCE_ACTION );

		$this->audit_logger->record(
			'diagnostics_self_test_triggered',
			'user',
			get_current_user_id(),
			array(
				'fail_count' => $fail_count,
				'ciphertext' => $ciphertext,
			),
			array(
				'fail_count' => Classification::PUBLIC,
				'ciphertext' => Classification::INTERNAL,
			),
			Classification::INTERNAL
		);

		$envelope = new JobEnvelope(
			self::JOB_TYPE,
			array( 'fail_count' => $fail_count ),
			array( 'fail_count' => Classification::PUBLIC )
		);
		$this->dispatcher->enqueue( $envelope );

		$this->redirect_and_exit( admin_url( 'admin.php?page=' . DiagnosticsPage::SLUG . '&self_test=triggered' ) );
	}

	/**
	 * Redirects and terminates the request. Overridable by tests, which
	 * cannot let a real exit call terminate the test process; see the
	 * class docblock.
	 *
	 * @param string $url The URL to redirect to.
	 */
	protected function redirect_and_exit( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * The queue's one real M00 handler: fixed, code-only behaviour driven
	 * solely by the operator-chosen fail_count and the job's own attempt
	 * number. Never runs arbitrary code, a hook, SQL, or a network
	 * request.
	 *
	 * @param array{job_id: string, job_type: string, attempt: int, payload: array<string, mixed>} $job The job.
	 *
	 * @throws RuntimeException On every attempt up to and including fail_count.
	 */
	public function handle_job( array $job ): void {
		$fail_count = (int) ( $job['payload']['fail_count'] ?? 5 );
		$attempt    = (int) $job['attempt'];

		if ( $attempt <= $fail_count ) {
			throw new RuntimeException(
				sprintf( 'Diagnostic self-test deliberate failure (attempt %d of %d).', $attempt, $fail_count )
			);
		}
	}
}
