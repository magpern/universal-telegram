<?php
/**
 * Uninstall routine.
 *
 * @package UniversalTelegram
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/vendor/autoload.php';

// By the time WordPress runs this file, plugins_loaded and init have
// already fired for whichever plugins were active during this request —
// this plugin, already deactivated as WordPress itself requires before
// deletion, was not among them. Action Scheduler's own bootstrap detects
// exactly this case and initializes itself immediately and synchronously.
require_once __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';

( new UniversalTelegram\Core\Lifecycle\Uninstaller() )->run();
