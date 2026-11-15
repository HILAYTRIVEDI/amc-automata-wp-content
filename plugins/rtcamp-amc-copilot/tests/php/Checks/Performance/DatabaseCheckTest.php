<?php
/**
 * Tests for perf.database.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Checks\Performance;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Fixtures write wp_options rows with an exact autoload value.

use AMC\Checks\Context;
use AMC\Checks\Performance\DatabaseCheck;
use AMC\Checks\Severity;
use AMC\Checks\Status;
use AMC\Tests\TestCase;

/**
 * Class DatabaseCheckTest
 */
final class DatabaseCheckTest extends TestCase {

	/**
	 * A tables seam with one table of `$total_mb`.
	 *
	 * @param float $total_mb Table size.
	 */
	private static function tables( float $total_mb ): callable {
		return static fn (): array => [
			'source' => 'information_schema',
			'tables' => [
				[
					'name'     => 'wp_posts',
					'rows'     => 10,
					'data_mb'  => $total_mb,
					'index_mb' => 0.0,
					'total_mb' => $total_mb,
				],
			],
		];
	}

	/**
	 * Store an option with an exact `autoload` column value.
	 *
	 * @param string $name     Option name.
	 * @param int    $bytes    Value length.
	 * @param string $autoload Raw autoload value.
	 */
	private static function option( string $name, int $bytes, string $autoload ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->options,
			[
				'option_name'  => $name,
				'option_value' => str_repeat( 'x', $bytes ),
				'autoload'     => $autoload,
			]
		);
	}

	/**
	 * Autoloaded bytes the default source reports.
	 */
	private static function default_autoload_kb(): float {
		return ( new DatabaseCheck( self::tables( 1.0 ) ) )->run( Context::for_tests() )->data['autoload_kb'];
	}

	public function test_the_default_source_counts_every_autoloaded_value_since_wordpress_6_6(): void {
		$before = self::default_autoload_kb();

		// WordPress 6.6+ writes 'on' / 'auto-on' / 'auto'; older sites still have 'yes'.
		self::option( 'amc_test_on', 10240, 'on' );
		self::option( 'amc_test_auto_on', 10240, 'auto-on' );
		self::option( 'amc_test_auto', 10240, 'auto' );
		self::option( 'amc_test_yes', 10240, 'yes' );
		// Not autoloaded.
		self::option( 'amc_test_off', 102400, 'off' );
		self::option( 'amc_test_auto_off', 102400, 'auto-off' );
		self::option( 'amc_test_no', 102400, 'no' );

		$this->assertEqualsWithDelta( 40.0, self::default_autoload_kb() - $before, 0.01, 'four 10 KB autoloaded options, the three non-autoloaded ones ignored' );
	}

	public function test_data_shape_and_status(): void {
		$result = ( new DatabaseCheck( self::tables( 50.0 ), static fn (): int => 716800 ) )->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( 'information_schema', $result->data['source'] );
		$this->assertSame( 50.0, $result->data['total_mb'] );
		$this->assertSame( 700.0, $result->data['autoload_kb'] );
		$this->assertFalse( $result->data['autoload_over'] );
	}

	public function test_over_800_kb_of_autoload_is_a_medium_finding(): void {
		$result = ( new DatabaseCheck( self::tables( 50.0 ), static fn (): int => 900 * 1024 ) )->run( Context::for_tests() );

		$this->assertSame( Status::WARN, $result->status );
		$this->assertCount( 1, $result->findings );
		$this->assertSame( Severity::MEDIUM, $result->findings[0]->severity );
		$this->assertSame( 900.0, $result->findings[0]->data['autoload_kb'] );
	}

	public function test_the_autoload_finding_keeps_one_key_while_the_size_moves(): void {
		$keys = [];
		foreach ( [ 900, 905, 1200 ] as $kb ) {
			$keys[] = ( new DatabaseCheck( self::tables( 50.0 ), static fn (): int => $kb * 1024 ) )->run( Context::for_tests() )->findings[0]->dedupe_key;
		}

		$this->assertSame( [ 'autoload:over', 'autoload:over', 'autoload:over' ], $keys, 'one open finding, not a new one (and a new alert) every day' );
	}

	public function test_unavailable_tables_are_info(): void {
		$result = ( new DatabaseCheck( static fn (): array => [ 'source' => 'unavailable' ], static fn (): int => 0 ) )->run( Context::for_tests() );

		$this->assertSame( Status::INFO, $result->status );
	}
}
