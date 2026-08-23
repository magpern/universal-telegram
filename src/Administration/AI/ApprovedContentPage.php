<?php
/**
 * Approved AI source content admin page.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\AI;

use UniversalTelegram\AI\Content\ApprovedContentRepository;
use UniversalTelegram\Administration\Hub\HubPage;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;

/**
 * The AI Content tab of the administration hub (docs/adr/0028 decision 2):
 * checkbox approval over the published, non-password-protected post/page
 * list, showing current approval/revision-staleness state. Gated on the
 * existing CapabilityRegistrar::MANAGE capability. Every action
 * independently re-verifies both the capability and its own nonce,
 * mirroring SettingsPage's exact existing pattern.
 *
 * Not declared final: tests override redirect_and_exit(), matching
 * SettingsPage's exact existing precedent.
 */
class ApprovedContentPage {

	public const TAB_ID            = 'ai-content';
	public const ADMIN_POST_ACTION = 'universal_telegram_ai_content_save';

	/**
	 * Constructor.
	 *
	 * @param ApprovedContentRepository $repository Reads/writes source approval state.
	 */
	public function __construct( private readonly ApprovedContentRepository $repository ) {}

	/**
	 * The admin-post save handler. Unchecked items are omitted from
	 * $_POST entirely, so every currently-listed candidate not present in
	 * the submitted set is explicitly revoked, not left untouched.
	 */
	public function handle_request(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'universal-telegram' ), '', 403 );
		}

		check_admin_referer( self::ADMIN_POST_ACTION );

		$submitted_ids = array();

		if ( isset( $_POST['approved'] ) && is_array( $_POST['approved'] ) ) {
			foreach ( wp_unslash( $_POST['approved'] ) as $post_id ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$submitted_ids[] = (int) $post_id;
			}
		}

		foreach ( $this->repository->list_candidates() as $candidate ) {
			$post_id = $candidate['post']->ID;

			if ( in_array( $post_id, $submitted_ids, true ) ) {
				$this->repository->approve( $post_id );
			} elseif ( $candidate['approved'] ) {
				$this->repository->revoke( $post_id );
			}
		}

		$this->redirect_and_exit( admin_url( 'admin.php?page=' . HubPage::SLUG . '&tab=' . self::TAB_ID ) );
	}

	/**
	 * Renders this tab's content only (no outer .wrap/<h1> — owned by
	 * HubPage).
	 */
	public function render_tab_content(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'universal-telegram' ) );
		}

		echo '<h2>' . esc_html__( 'Approved AI Source Content', 'universal-telegram' ) . '</h2>';
		echo '<p>' . esc_html__( 'Only checked, published, non-password-protected content may be used to ground an AI draft. Editing a post after approving it excludes it again until re-approved.', 'universal-telegram' ) . '</p>';

		$candidates = $this->repository->list_candidates();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ADMIN_POST_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ADMIN_POST_ACTION ) . '" />';
		echo '<table class="widefat"><thead><tr><th></th><th>' . esc_html__( 'Title', 'universal-telegram' ) . '</th><th>' . esc_html__( 'Type', 'universal-telegram' ) . '</th><th>' . esc_html__( 'Status', 'universal-telegram' ) . '</th></tr></thead><tbody>';

		foreach ( $candidates as $candidate ) {
			$post = $candidate['post'];

			$status = $candidate['stale']
				? esc_html__( 'Approved, but edited since — excluded until re-approved', 'universal-telegram' )
				: ( $candidate['approved'] ? esc_html__( 'Approved', 'universal-telegram' ) : esc_html__( 'Not approved', 'universal-telegram' ) );

			printf(
				'<tr><td><input type="checkbox" name="approved[]" value="%1$d" %2$s /></td><td>%3$s</td><td>%4$s</td><td>%5$s</td></tr>',
				(int) $post->ID,
				checked( $candidate['approved'], true, false ),
				esc_html( get_the_title( $post ) ),
				esc_html( $post->post_type ),
				esc_html( $status )
			);
		}

		echo '</tbody></table>';
		submit_button( __( 'Save Approvals', 'universal-telegram' ) );
		echo '</form>';
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
