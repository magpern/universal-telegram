<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Automations;

use UniversalTelegram\AI\Config\AIProviderRepository;
use UniversalTelegram\Administration\Automations\IntelligencePanel;
use UniversalTelegram\Automations\Intelligence\OperationalSummaryRepository;
use UniversalTelegram\Automations\Intelligence\SummaryAiRepository;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use WP_UnitTestCase;

final class IntelligencePanelTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}universal_telegram_operational_summary_ai_drafts" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}universal_telegram_operational_summary_runs" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$wpdb->prefix}universal_telegram_ai_config SET enabled = 0, model = '', api_key_ciphertext = NULL WHERE id = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function panel(): IntelligencePanel {
		return new IntelligencePanel(
			new SummaryAiRepository( new SchemaHealth(), new CredentialVault() ),
			new OperationalSummaryRepository( new SchemaHealth() ),
			new AIProviderRepository( new SchemaHealth(), new CredentialVault() )
		);
	}

	public function test_renders_nothing_when_no_summary_exists(): void {
		ob_start();
		$this->panel()->render();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_renders_ai_disabled_notice_when_provider_not_ready(): void {
		( new OperationalSummaryRepository( new SchemaHealth() ) )->create_or_get_for_date( gmdate( 'Y-m-d' ), gmdate( 'Y-m-d 00:00:00' ), gmdate( 'Y-m-d H:i:s' ) );

		ob_start();
		$this->panel()->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Enable AI', $output );
	}

	public function test_discard_requires_manage_capability(): void {
		$panel = $this->panel();

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$this->expectException( \WPDieException::class );
		$panel->handle_request();
	}
}
