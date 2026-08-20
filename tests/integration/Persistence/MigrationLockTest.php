<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Persistence;

use UniversalTelegram\Persistence\MigrationLock;
use WP_UnitTestCase;

final class MigrationLockTest extends WP_UnitTestCase {

	public function test_a_fresh_lock_cannot_be_acquired_twice(): void {
		$lock = new MigrationLock();

		$first = $lock->acquire();
		$this->assertNotNull( $first );

		$second = $lock->acquire();
		$this->assertNull( $second );

		$lock->release( $first );
	}

	public function test_stale_lock_reclamation_and_the_original_holders_release_is_a_safe_noop(): void {
		global $wpdb;

		$lock = new MigrationLock();

		$first_handle = $lock->acquire();
		$this->assertNotNull( $first_handle );

		// Simulate staleness: rewrite the option's embedded timestamp into
		// the past, exactly as if five minutes had really elapsed.
		$stale_value = $first_handle->token() . '|' . ( time() - 3600 );
		$wpdb->update(
			$wpdb->options,
			array( 'option_value' => $stale_value ),
			array( 'option_name' => 'universal_telegram_migration_lock' )
		);
		wp_cache_delete( 'universal_telegram_migration_lock', 'options' );

		$second_handle = $lock->acquire();
		$this->assertNotNull( $second_handle );
		$this->assertNotSame( $first_handle->token(), $second_handle->token() );

		// The first process, unaware any of this happened, releases using
		// its own original (now-stale) value.
		$lock->release( $first_handle );

		// The second holder's lock must be left completely untouched.
		$this->assertSame( $second_handle->value(), get_option( 'universal_telegram_migration_lock' ) );

		$lock->release( $second_handle );
	}
}
