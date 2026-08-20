<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Events\Emitters;

use UniversalTelegram\Persistence\Migrator;
use WP_UnitTestCase;

final class ContentEmitterTest extends WP_UnitTestCase {

	private function count_for( string $event_type ): int {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE;

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_type = %s", $event_type ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	public function test_publishing_a_post_emits_exactly_one_occurrence(): void {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'draft' ) );

		wp_publish_post( $post->ID );

		$this->assertSame( 1, $this->count_for( 'wordpress.post_published' ) );
	}

	public function test_a_duplicate_publish_transition_firing_for_the_same_post_collapses_to_one_row(): void {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'draft' ) );

		// Simulates the documented multiple-firing behaviour for the same
		// logical publish action.
		do_action( 'transition_post_status', 'publish', 'draft', $post );
		do_action( 'transition_post_status', 'publish', 'draft', $post );

		$this->assertSame( 1, $this->count_for( 'wordpress.post_published' ) );
	}

	public function test_a_non_publish_transition_never_emits(): void {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'draft' ) );

		do_action( 'transition_post_status', 'pending', 'draft', $post );

		$this->assertSame( 0, $this->count_for( 'wordpress.post_published' ) );
	}

	public function test_comment_submitted_is_emitted(): void {
		$post       = self::factory()->post->create();
		$comment_id = self::factory()->comment->create( array( 'comment_post_ID' => $post ) );

		do_action( 'comment_post', $comment_id, 1 );

		$this->assertSame( 1, $this->count_for( 'wordpress.comment_submitted' ) );
	}
}
