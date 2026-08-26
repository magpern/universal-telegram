<?php
/**
 * Cross-plugin quiescence signal.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Migration;

/**
 * In-process, no-REST cross-plugin exposure of the current quiescence
 * signal (docs/adr/0040 §8), returned by `Core\Plugin::quiescence_status()`.
 * Frozen shape: Support Chat's `InProcessLegacyExportClient`-style consumer
 * depends on this exact constructor signature and these exact two public,
 * readonly properties.
 */
final class QuiescenceStatus {

	/**
	 * Constructor.
	 *
	 * @param bool                    $is_quiescent Whether the signal is currently quiescent: `state === 'quiescent'` AND the deferred-update backlog is empty.
	 * @param \DateTimeImmutable|null $since        When the current `quiescent` state was entered, or null when not currently quiescent.
	 */
	public function __construct(
		public readonly bool $is_quiescent,
		public readonly ?\DateTimeImmutable $since
	) {}
}
