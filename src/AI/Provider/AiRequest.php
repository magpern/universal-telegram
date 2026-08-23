<?php
/**
 * Provider-neutral AI completion request.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\AI\Provider;

/**
 * The bounded, fully-assembled request AiProviderInterface::complete()
 * accepts. Built only by AI\Draft\PromptBuilder (docs/adr/0028 decision 7)
 * — the fixed system/user split IS the policy-authority boundary: a
 * provider adapter has no way to elevate user content into the system
 * role, since that separation is fixed at this value object's
 * construction, not at adapter call time.
 */
final class AiRequest {

	/**
	 * Constructor.
	 *
	 * @param string $model               The administrator-configured model identifier.
	 * @param string $system_prompt       The fixed, non-overridable policy/system message.
	 * @param string $user_content        The delimited source excerpts and conversation context.
	 * @param int    $max_output_chars    The hard output-length bound.
	 */
	public function __construct(
		private readonly string $model,
		private readonly string $system_prompt,
		private readonly string $user_content,
		private readonly int $max_output_chars
	) {}

	/**
	 * The administrator-configured model identifier.
	 *
	 * @return string
	 */
	public function model(): string {
		return $this->model;
	}

	/**
	 * The fixed, non-overridable policy/system message.
	 *
	 * @return string
	 */
	public function system_prompt(): string {
		return $this->system_prompt;
	}

	/**
	 * The delimited source excerpts and conversation context.
	 *
	 * @return string
	 */
	public function user_content(): string {
		return $this->user_content;
	}

	/**
	 * The hard output-length bound.
	 *
	 * @return int
	 */
	public function max_output_chars(): int {
		return $this->max_output_chars;
	}
}
