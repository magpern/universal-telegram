<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Core;

use UniversalTelegram\Core\Plugin;
use WP_UnitTestCase;

/**
 * M06.3.1 (ADR-0025): deleting a WordPress user must revoke the bearer
 * secret and clear owner_user_id for every conversation that user owned,
 * via the `deleted_user` hook wired in Plugin::init(). Exercises the real,
 * already-booted composition root rather than a hand-built repository, so
 * this is the one place the wiring itself — not just
 * ConversationRepository::release_owner_conversations() in isolation
 * (already covered by ConversationRepositoryTest) — is proven.
 */
final class PluginAccountDeletionTest extends WP_UnitTestCase {

	public function test_deleting_a_user_clears_ownership_and_revokes_the_secret(): void {
		$conversations = Plugin::instance()->conversation_repository();
		$this->assertNotNull( $conversations );

		$user_id      = self::factory()->user->create();
		$conversation = $conversations->create( 'uuid-account-deletion', 'hash', 1, null, null, $user_id, 'Deleted User' );
		$this->assertNotNull( $conversation );

		// Fires the same hook wp_delete_user() fires, without depending on
		// wp-admin/includes/user.php being loaded in this test bootstrap —
		// this test's own subject is the `deleted_user` wiring in
		// Plugin::init(), not WordPress core's own deletion routine.
		do_action( 'deleted_user', $user_id );

		$refreshed = $conversations->find( $conversation->id() );
		$this->assertNull( $refreshed->owner_user_id() );
		$this->assertNull( $refreshed->secret_hash() );
	}
}
