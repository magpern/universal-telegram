<?php
/**
 * Provider-neutral AI completion contract.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\AI\Provider;

/**
 * The one boundary every AI provider adapter implements (docs/adr/0028
 * decision 3). M09 ships exactly one implementation, OpenAi\OpenAiAdapter;
 * this interface exists so a future milestone can add a second adapter
 * without revisiting the persistence or draft-lifecycle model.
 */
interface AiProviderInterface {

	/**
	 * Produces one bounded completion. Never throws for a well-formed
	 * error response (including a network-transport failure, surfaced as
	 * AiResult::is_network_error()) — only a truly malformed, unparseable
	 * response may throw.
	 *
	 * @param AiRequest $request The bounded, fully-assembled request.
	 *
	 * @return AiResult
	 */
	public function complete( AiRequest $request ): AiResult;
}
