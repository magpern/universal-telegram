<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Core;

use UniversalTelegram\Core\Plugin;
use UniversalTelegram\Persistence\MigrationLock;
use UniversalTelegram\Persistence\Migrator;
use WP_UnitTestCase;

/**
 * ADR-0040 §2 entry point #7, PO-confirmed trade-off: during any non-idle
 * quiescence state, deleting a WP user must NOT clean up that user's
 * conversation data — this defers, permanently for that state, both the
 * visitor-owner cleanup and the operator-identity cleanup closures wired
 * in Core\Plugin::init(). Exercises the real, already-booted composition
 * root, matching PluginAccountDeletionTest's own precedent.
 */
final class DeletedUserQuiescenceTest extends WP_UnitTestCase {

	protected function tearDown(): void {
		global $wpdb;
		$wpdb->query( 'UPDATE ' . $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE . " SET state = 'idle', updated_at = NOW() WHERE id = 1" );
		parent::tearDown();
	}

	public function test_deleting_a_user_does_not_clear_ownership_while_draining(): void {
		global $wpdb;

		( new Migrator( new MigrationLock() ) )->maybe_migrate();
		$wpdb->db_connect( true );

		$wpdb->query( 'UPDATE ' . $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE . " SET state = 'draining', updated_at = NOW() WHERE id = 1" );

		$conversations = Plugin::instance()->conversation_repository();
		$this->assertNotNull( $conversations );

		$user_id      = self::factory()->user->create();
		$conversation = $conversations->create( 'uuid-quiescence-deletion', 'hash', 1, null, null, $user_id, 'Deleted User' );
		$this->assertNotNull( $conversation );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.HookCommentWrongStyle, WooCommerce.Commenting.CommentHooks.MissingHookComment, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'deleted_user', $user_id, null, null, null );

		$refreshed = $conversations->find( $conversation->id() );
		$this->assertSame( $user_id, $refreshed->owner_user_id(), 'Ownership must be left untouched while quiescence-blocked.' );
		$this->assertNotNull( $refreshed->secret_hash(), 'The secret must not be revoked while quiescence-blocked.' );
	}
}
