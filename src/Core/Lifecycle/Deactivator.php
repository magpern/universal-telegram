<?php
/**
 * Plugin deactivation.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Core\Lifecycle;

/**
 * Deactivation removes nothing; uninstall (Work Package 10) is the only
 * place data or capabilities are ever removed.
 */
final class Deactivator {

	/**
	 * Deactivation callback. Intentionally empty at M00: deactivation must
	 * not remove data, and the plugin owns no scheduled state that needs
	 * pausing beyond what WordPress itself already does by unloading it.
	 */
	public function deactivate(): void {}
}
