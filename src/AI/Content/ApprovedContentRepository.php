<?php
/**
 * Approved AI source-content selection and bounded retrieval.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\AI\Content;

use UniversalTelegram\Conversations\MessageRepository;

/**
 * Source-only, allow-listed retrieval (docs/adr/0028 decision 2): only
 * explicitly administrator-approved, published, non-password-protected
 * posts/pages are eligible, re-validated against a captured revision
 * marker at approval time — a source edited since approval is excluded
 * until re-approved. top_matches() takes no free-text query parameter
 * from any caller; it derives its own query exclusively from the
 * conversation's own most recent visitor message, so no operator or
 * request can turn this into an arbitrary content-search surface.
 *
 * Approval state lives in post meta, not a dedicated table: it is a
 * property of the existing post/page row, mirroring how WordPress
 * already models per-post flags, and needs no migration.
 */
final class ApprovedContentRepository {

	private const APPROVED_META_KEY          = '_universal_telegram_ai_approved';
	private const APPROVED_REVISION_META_KEY = '_universal_telegram_ai_approved_revision';

	private const MAX_SOURCES        = 3;
	private const EXCERPT_MAX_LENGTH = 800;

	/**
	 * Constructor.
	 *
	 * @param MessageRepository $message_repository Supplies the conversation's own last visitor message.
	 */
	public function __construct( private readonly MessageRepository $message_repository ) {}

	/**
	 * Marks a post/page approved, capturing its current revision marker.
	 *
	 * @param int $post_id The post/page id.
	 *
	 * @return bool Whether the post is eligible and was approved.
	 */
	public function approve( int $post_id ): bool {
		$post = get_post( $post_id );

		if ( null === $post || ! $this->is_eligible_post( $post ) ) {
			return false;
		}

		update_post_meta( $post_id, self::APPROVED_META_KEY, '1' );
		update_post_meta( $post_id, self::APPROVED_REVISION_META_KEY, $post->post_modified_gmt );

		return true;
	}

	/**
	 * Revokes approval for a post/page.
	 *
	 * @param int $post_id The post/page id.
	 */
	public function revoke( int $post_id ): void {
		delete_post_meta( $post_id, self::APPROVED_META_KEY );
		delete_post_meta( $post_id, self::APPROVED_REVISION_META_KEY );
	}

	/**
	 * Whether a post is currently approved AND unedited since that
	 * approval (its post_modified_gmt still matches the captured marker).
	 *
	 * @param int $post_id The post/page id.
	 */
	public function is_currently_approved( int $post_id ): bool {
		$post = get_post( $post_id );

		if ( null === $post ) {
			return false;
		}

		if ( '1' !== get_post_meta( $post_id, self::APPROVED_META_KEY, true ) ) {
			return false;
		}

		$approved_revision = (string) get_post_meta( $post_id, self::APPROVED_REVISION_META_KEY, true );

		return $approved_revision === $post->post_modified_gmt;
	}

	/**
	 * Every published, non-password-protected post/page, for the approval
	 * management admin screen — each entry annotated with its current
	 * approval/staleness state. Bounded to WordPress' own query defaults;
	 * this is an administration listing, not the retrieval path.
	 *
	 * @return array<int, array{post: \WP_Post, approved: bool, stale: bool}>
	 */
	public function list_candidates(): array {
		$query = new \WP_Query(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => 200,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		$candidates = array();

		foreach ( $query->posts as $post ) {
			$is_marked_approved = '1' === get_post_meta( $post->ID, self::APPROVED_META_KEY, true );
			$candidates[]        = array(
				'post'     => $post,
				'approved' => $is_marked_approved,
				'stale'    => $is_marked_approved && ! $this->is_currently_approved( $post->ID ),
			);
		}

		return $candidates;
	}

	/**
	 * The bounded, ranked set of currently-approved source excerpts
	 * relevant to a conversation's own last visitor message. Returns an
	 * empty array if there is no visitor message yet, or no approved
	 * source matches — callers must treat an empty result as the fixed
	 * `no_matching_source` terminal case (docs/adr/0028 decision 2), never
	 * as a signal to fall back to unsourced generation.
	 *
	 * @param int $conversation_id The conversation to derive the query from.
	 *
	 * @return array<int, ApprovedSource>
	 */
	public function top_matches( int $conversation_id ): array {
		$last_visitor_message = $this->message_repository->latest_visitor_message( $conversation_id );

		if ( null === $last_visitor_message ) {
			return array();
		}

		$plaintext = $this->message_repository->decrypt( $last_visitor_message );

		if ( null === $plaintext || '' === trim( $plaintext ) ) {
			return array();
		}

		$search_terms = $this->normalize_to_search_string( $plaintext );

		if ( '' === $search_terms ) {
			return array();
		}

		$query = new \WP_Query(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'has_password'   => false,
				's'              => $search_terms,
				'meta_key'       => self::APPROVED_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'posts_per_page' => self::MAX_SOURCES * 4,
				'no_found_rows'  => true,
			)
		);

		$sources = array();

		foreach ( $query->posts as $post ) {
			if ( count( $sources ) >= self::MAX_SOURCES ) {
				break;
			}

			if ( ! $this->is_currently_approved( $post->ID ) ) {
				continue;
			}

			$sources[] = new ApprovedSource(
				$post->ID,
				$post->post_modified_gmt,
				get_the_title( $post ),
				$this->bounded_excerpt( $post )
			);
		}

		return $sources;
	}

	/**
	 * Whether a post is eligible for approval at all: published and not
	 * password-protected. Unpublished, private, and password-protected
	 * content can never be approved (docs/adr/0028 decision 2).
	 *
	 * @param \WP_Post $post The candidate post.
	 */
	private function is_eligible_post( \WP_Post $post ): bool {
		return 'publish' === $post->post_status && '' === $post->post_password;
	}

	/**
	 * A fixed-length, tag-stripped lead excerpt — never the full post body.
	 *
	 * @param \WP_Post $post The source post.
	 */
	private function bounded_excerpt( \WP_Post $post ): string {
		$plain = wp_strip_all_tags( $post->post_content );
		$plain = trim( preg_replace( '/\s+/', ' ', $plain ) ?? $plain );

		if ( strlen( $plain ) <= self::EXCERPT_MAX_LENGTH ) {
			return $plain;
		}

		return substr( $plain, 0, self::EXCERPT_MAX_LENGTH );
	}

	/**
	 * Lowercases, strips punctuation, and drops a small fixed stopword
	 * list from free text, producing a bounded space-separated search
	 * string for WP_Query's own 's' parameter. This is the only place
	 * conversation content is ever turned into a query — never exposed as
	 * a parameter any caller can influence directly.
	 *
	 * @param string $text The visitor message plaintext.
	 */
	private function normalize_to_search_string( string $text ): string {
		static $stopwords = array( 'the', 'a', 'an', 'is', 'are', 'to', 'of', 'and', 'or', 'i', 'you', 'it', 'in', 'on', 'for', 'my', 'me', 'do', 'does', 'how', 'can', 'what' );

		$lower  = strtolower( $text );
		$plain  = preg_replace( '/[^a-z0-9\s]/', ' ', $lower ) ?? $lower;
		$tokens = array_filter(
			preg_split( '/\s+/', trim( $plain ) ) ?: array(),
			static fn( string $token ): bool => strlen( $token ) > 2 && ! in_array( $token, $stopwords, true )
		);

		// WP_Query's default 's' matching is bounded regardless of input
		// length, but this is capped defensively to keep the derived query
		// itself small and predictable.
		return implode( ' ', array_slice( array_values( $tokens ), 0, 12 ) );
	}
}
