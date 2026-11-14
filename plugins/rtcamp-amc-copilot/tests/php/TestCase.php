<?php
/**
 * Base class for all AMC Copilot PHPUnit tests.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests;

use AMC\Core\Settings;
use WP_UnitTestCase;

/**
 * Class TestCase
 *
 * - Runs inside WordPress (wp-phpunit); every test is wrapped in a DB
 *   transaction that is rolled back, including the AMC custom tables
 *   (created once in tests/bootstrap.php).
 * - Hooks added in a test are removed after it (WP_UnitTestCase backs them up).
 * - Settings start from defaults in every test.
 * - Mail is sent from a valid address, whatever the test site's host.
 *
 * @since 0.1.0
 */
abstract class TestCase extends WP_UnitTestCase {

	/**
	 * Reset AMC options before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		delete_option( Settings::OPTION );

		// wp_mail() defaults the sender to wordpress@<site host>. When the test
		// site runs on a bare host such as "localhost", PHPMailer rejects that
		// address and no mail reaches the mock mailbox, so give tests a valid one.
		add_filter( 'wp_mail_from', static fn (): string => 'wordpress@example.org' );
	}

	/**
	 * Register checks for this test only via the amc_register_checks filter.
	 *
	 * @param \AMC\Checks\Check ...$checks Checks.
	 */
	protected function register_checks( \AMC\Checks\Check ...$checks ): void {
		add_filter(
			'amc_register_checks',
			static function ( array $registered ) use ( $checks ): array {
				$ids = array_map( static fn ( \AMC\Checks\Check $check ): string => $check->id(), $checks );

				// Registry keeps the first check per id, so drop the real check this one replaces.
				$registered = array_filter(
					$registered,
					static fn ( $check ): bool => ! $check instanceof \AMC\Checks\Check || ! in_array( $check->id(), $ids, true )
				);

				return array_merge( $registered, $checks );
			}
		);
	}

	/**
	 * Load a fixture file from tests/php/fixtures.
	 *
	 * @param string $path Relative path without .php, e.g. 'updates-core/minor'.
	 *
	 * @return mixed Whatever the fixture returns.
	 */
	protected function fixture( string $path ): mixed {
		return require __DIR__ . '/fixtures/' . $path . '.php';
	}
}
