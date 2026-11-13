<?php
/**
 * Test helper: swap the registered checks for fakes of the same id.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Support;

use AMC\Checks\Check;

/**
 * Trait ReplacesChecks
 *
 * The lane providers already register the real check under its id, and the
 * first registration wins. A test that wants a check with fake seams in the
 * Runner removes the provider filters first (WP_UnitTestCase restores them
 * after the test).
 */
trait ReplacesChecks {

	/**
	 * Register only these checks.
	 *
	 * @param Check ...$checks Checks.
	 */
	protected function replace_checks( Check ...$checks ): void {
		remove_all_filters( 'amc_register_checks' );
		$this->register_checks( ...$checks );
	}
}
