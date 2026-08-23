<?php
/**
 * Assembled prompt bundle.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\AI\Draft;

use UniversalTelegram\AI\Provider\AiRequest;

/**
 * The output of PromptBuilder::build(): a ready-to-send AiRequest plus the
 * traceability fields AiDraftRepository persists alongside the eventual
 * draft (docs/adr/0028 decision 4) — never the raw prompt text itself,
 * only a fingerprint of it.
 */
final class BuiltPrompt {

	/**
	 * Constructor.
	 *
	 * @param AiRequest $request             The bounded, fully-assembled provider request.
	 * @param string    $source_ids_json     JSON array of {post_id, revision_id} for every source used.
	 * @param string    $context_fingerprint SHA-256 of the exact submitted system+user content.
	 */
	public function __construct(
		private readonly AiRequest $request,
		private readonly string $source_ids_json,
		private readonly string $context_fingerprint
	) {}

	public function request(): AiRequest {
		return $this->request;
	}

	public function source_ids_json(): string {
		return $this->source_ids_json;
	}

	public function context_fingerprint(): string {
		return $this->context_fingerprint;
	}
}
