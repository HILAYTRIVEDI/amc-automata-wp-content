<?php
/**
 * Tests for the Util helper.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Tests;

use AMC_Automata_Features\Features\Helpers\Util;

/**
 * Class UtilTest
 */
final class UtilTest extends TestCase {

	/**
	 * Data directory the tests create and remove.
	 *
	 * @var string
	 */
	private string $data_dir;

	/**
	 * Create inc/data/ with one data file, and a file outside it that a traversal would reach.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->data_dir = AMC_AUTOMATA_FEATURES_FEATURES_PATH . 'inc/data';

		if ( ! is_dir( $this->data_dir ) ) {
			mkdir( $this->data_dir, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		}

		file_put_contents( $this->data_dir . '/util-test.php', "<?php\nreturn [ 'loaded' => true ];\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( AMC_AUTOMATA_FEATURES_FEATURES_PATH . 'inc/util-test-outside.php', "<?php\nreturn [ 'escaped' => true ];\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Remove the files the tests created.
	 */
	public function tear_down(): void {
		wp_delete_file( $this->data_dir . '/util-test.php' );
		wp_delete_file( AMC_AUTOMATA_FEATURES_FEATURES_PATH . 'inc/util-test-outside.php' );

		if ( is_dir( $this->data_dir ) && [] === array_diff( (array) scandir( $this->data_dir ), [ '.', '..' ] ) ) {
			rmdir( $this->data_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}

		parent::tear_down();
	}

	/**
	 * A valid slug loads the matching file from inc/data/.
	 */
	public function test_get_data_loads_a_data_file(): void {
		$this->assertSame( [ 'loaded' => true ], Util::get_data( 'util-test' ) );
	}

	/**
	 * A missing file returns the default value.
	 */
	public function test_get_data_returns_the_default_for_a_missing_file(): void {
		$this->assertSame( 'fallback', Util::get_data( 'does-not-exist', 'fallback' ) );
	}

	/**
	 * Slugs that could leave inc/data/ return the default without loading anything.
	 *
	 * @dataProvider provide_unsafe_slugs
	 *
	 * @param string $slug Unsafe slug.
	 */
	public function test_get_data_rejects_unsafe_slugs( string $slug ): void {
		$this->assertSame( 'fallback', Util::get_data( $slug, 'fallback' ) );
	}

	/**
	 * Slugs with path separators, traversal or other characters outside the allowed set.
	 *
	 * @return array<string, array{string}>
	 */
	public static function provide_unsafe_slugs(): array {
		return [
			'parent directory' => [ '../util-test-outside' ],
			'nested traversal' => [ 'a/../../util-test-outside' ],
			'backslash'        => [ '..\\util-test-outside' ],
			'absolute path'    => [ '/etc/passwd' ],
			'null byte'        => [ "util-test\0" ],
			'empty'            => [ '' ],
		];
	}
}
