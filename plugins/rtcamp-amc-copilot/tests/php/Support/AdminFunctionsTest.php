<?php
/**
 * Tests for AdminFunctions.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Support;

use AMC\Support\AdminFunctions;
use AMC\Tests\TestCase;

/**
 * Class AdminFunctionsTest
 */
final class AdminFunctionsTest extends TestCase {

	/**
	 * Every mapped function is available after ensure(), as it must be when a
	 * check runs from WP-Cron (no wp-admin includes loaded).
	 */
	public function test_ensure_loads_each_admin_function(): void {
		foreach ( [ 'get_plugins', 'get_mu_plugins', 'get_dropins', '_get_dropins', 'wp_check_php_version', 'plugins_api', 'themes_api' ] as $function_name ) {
			$this->assertTrue( AdminFunctions::ensure( $function_name ), $function_name );
			$this->assertTrue( function_exists( $function_name ), $function_name );
		}
	}

	public function test_unknown_function_is_not_loaded(): void {
		$this->assertFalse( AdminFunctions::ensure( 'amc_no_such_function' ) );
	}
}
