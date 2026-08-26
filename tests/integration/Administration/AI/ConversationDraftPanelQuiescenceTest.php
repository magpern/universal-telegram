<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\AI;

use UniversalTelegram\Administration\AI\ConversationDraftPanel;
use UniversalTelegram\AI\Config\AIProviderRepository;
use UniversalTelegram\AI\Draft\AiDraftRepository;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Migration\DeferredUpdateRepository;
use UniversalTelegram\Migration\QuiescenceGate;
use UniversalTelegram\Migration\QuiescenceTransitionRepository;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use WP_UnitTestCase;

/**
 * ADR-0040 §2 entry point #6: review/approve/discard refuses outside idle.
 */
final class ConversationDraftPanelQuiescenceTest extends WP_UnitTestCase {

	private QuiescenceGate $gate;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$wpdb->query( 'UPDATE ' . $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE . " SET state = 'idle', updated_at = NOW() WHERE id = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$schema_health = new SchemaHealth();
		$this->gate    = new QuiescenceGate(
			$schema_health,
			new DeferredUpdateRepository( $schema_health, new CredentialVault() ),
			new QuiescenceTransitionRepository()
		);
	}

	protected function tearDown(): void {
		unset( $_POST['_wpnonce'], $_POST['op'], $_POST['draft_id'], $_POST['conversation_id'] );
		parent::tearDown();
	}

	public function test_review_action_is_blocked_with_409_outside_idle(): void {
		$this->gate->enter();

		$operator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $operator );

		$_POST['_wpnonce']        = wp_create_nonce( ConversationDraftPanel::NONCE_ACTION );
		$_POST['op']              = 'approve';
		$_POST['draft_id']        = '1';
		$_POST['conversation_id'] = '1';

		$schema_health = new SchemaHealth();
		$panel         = new ConversationDraftPanel(
			new AiDraftRepository( $schema_health, new CredentialVault() ),
			new AIProviderRepository( $schema_health, new CredentialVault() ),
			$this->gate
		);

		$this->expectException( \WPDieException::class );
		$panel->handle_request();
	}
}
