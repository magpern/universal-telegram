<?php
/**
 * Post-published and comment-submitted event emission.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Events\Emitters;

use UniversalTelegram\Events\Registry;
use UniversalTelegram\Privacy\Classification;
use WP_Post;

/**
 * Thin, reviewed callbacks on transition_post_status (filtered to a genuine
 * publish transition) and comment_post. Both are deduplicable: a stable
 * per-post or per-comment idempotency key collapses any duplicate firing
 * for the same underlying occurrence (M02 plan §8).
 */
final class ContentEmitter {

	public const POST_PUBLISHED    = 'wordpress.post_published';
	public const COMMENT_SUBMITTED = 'wordpress.comment_submitted';

	/**
	 * Registers this emitter's event types.
	 *
	 * @param Registry $registry The current request's event registry.
	 */
	public function register_event_types( Registry $registry ): void {
		$registry->register(
			self::POST_PUBLISHED,
			1,
			array(
				'subject.post_id'   => Classification::PUBLIC,
				'payload.post_type' => Classification::PUBLIC,
			),
			array( 'subject.post_id', 'payload.post_type' ),
			array( 'subject.post_id', 'payload.post_type' )
		);

		$registry->register(
			self::COMMENT_SUBMITTED,
			1,
			array(
				'subject.comment_id' => Classification::PUBLIC,
				'subject.post_id'    => Classification::PUBLIC,
			),
			array( 'subject.comment_id', 'subject.post_id' ),
			array( 'subject.comment_id', 'subject.post_id' )
		);
	}

	/**
	 * The transition_post_status callback. Emits only for a genuine
	 * new->publish transition.
	 *
	 * @param string  $new_status The post's new status.
	 * @param string  $old_status The post's previous status.
	 * @param WP_Post $post       The post transitioning status.
	 */
	public function on_post_status_transition( string $new_status, string $old_status, WP_Post $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}

		universal_telegram_emit_event(
			self::POST_PUBLISHED,
			array(
				'subject' => array( 'post_id' => (int) $post->ID ),
				'payload' => array( 'post_type' => $post->post_type ),
			),
			hash( 'sha256', 'post:' . $post->ID . ':publish' )
		);
	}

	/**
	 * The comment_post callback.
	 *
	 * @param int        $comment_id       The newly inserted comment's ID.
	 * @param int|string $comment_approved 1, 0, or 'spam'.
	 */
	public function on_comment_submitted( int $comment_id, $comment_approved ): void {
		$comment = get_comment( $comment_id );

		if ( null === $comment ) {
			return;
		}

		universal_telegram_emit_event(
			self::COMMENT_SUBMITTED,
			array(
				'subject' => array(
					'comment_id' => $comment_id,
					'post_id'    => (int) $comment->comment_post_ID,
				),
			),
			hash( 'sha256', 'comment:' . $comment_id )
		);
	}
}
