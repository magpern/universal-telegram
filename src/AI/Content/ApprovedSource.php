<?php
/**
 * Approved AI source excerpt value object.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\AI\Content;

/**
 * One bounded excerpt returned by ApprovedContentRepository::top_matches()
 * (docs/adr/0028 decision 2): a title plus a fixed-length lead excerpt,
 * never the full post body.
 */
final class ApprovedSource {

	/**
	 * Constructor.
	 *
	 * @param int    $post_id     The approved post/page id.
	 * @param string $revision_id The post_modified_gmt this excerpt was drawn from.
	 * @param string $title       The post/page title.
	 * @param string $excerpt     A bounded (<=800 char) lead excerpt.
	 */
	public function __construct(
		private readonly int $post_id,
		private readonly string $revision_id,
		private readonly string $title,
		private readonly string $excerpt
	) {}

	public function post_id(): int {
		return $this->post_id;
	}

	public function revision_id(): string {
		return $this->revision_id;
	}

	public function title(): string {
		return $this->title;
	}

	public function excerpt(): string {
		return $this->excerpt;
	}
}
