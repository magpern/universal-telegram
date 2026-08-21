<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Automations;

use UniversalTelegram\Administration\Automations\EventHistoryPage;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use WP_UnitTestCase;

final class EventHistoryPageTest extends WP_UnitTestCase {

	public function test_renders_only_projected_fields_json_content(): void {
		global $wpdb;

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		( new \UniversalTelegram\Core\Capabilities\CapabilityRegistrar() )->grant_to_administrator();
		wp_set_current_user( $admin );

		$table = $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE;
		$wpdb->insert(
			$table,
			array(
				'event_id'              => hash( 'sha256', 'page-test' ),
				'event_type'            => 'wordpress.user_registered',
				'schema_version'        => 1,
				'occurred_at'           => current_time( 'mysql', true ),
				'source'                => 'wordpress_core',
				'projected_fields_json' => wp_json_encode( array( 'subject' => array( 'user_id' => 42 ) ) ),
				'created_at'            => current_time( 'mysql', true ),
			)
		);

		$page = new EventHistoryPage( new SchemaHealth() );

		ob_start();
		$page->render_tab_content();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'wordpress.user_registered', $output );
		$this->assertStringContainsString( 'user_id', $output );
		$this->assertStringNotContainsString( 'INTERNAL', $output );
	}

	public function test_unauthorized_user_is_denied(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$page = new EventHistoryPage( new SchemaHealth() );

		$this->expectException( \WPDieException::class );
		$page->render_tab_content();
	}
}
