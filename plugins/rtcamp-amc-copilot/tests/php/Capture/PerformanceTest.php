<?php
/**
 * Performance proof for the capture layer.
 *
 * 1,000 simulated requests, each one constructing and registering the whole
 * capture layer and recording a handful of events. Asserts that the p95 of the
 * per-request cost is under 2 ms and that nothing touches the database before
 * `shutdown`. Set AMC_BENCH_REPORT=1 to print the numbers:
 *
 *   AMC_BENCH_REPORT=1 XDEBUG_MODE=off WP_PHPUNIT__TESTS_CONFIG=... vendor/bin/phpunit tests/php/Capture/PerformanceTest.php
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Capture;

use AMC\Capture\Buffer;
use AMC\Capture\ChangeLog;
use AMC\Capture\DeprecationCapture;
use AMC\Capture\ErrorCapture;
use AMC\Capture\QuerySampler;
use AMC\Tests\TestCase;

/**
 * Class PerformanceTest
 */
final class PerformanceTest extends TestCase {

	/**
	 * Simulated requests.
	 */
	private const REQUESTS = 1000;

	/**
	 * Budget, milliseconds, at p95.
	 */
	private const BUDGET_MS = 2.0;

	/**
	 * Percentile of a list of samples.
	 *
	 * @param list<float> $samples Samples.
	 * @param float       $p       0..1.
	 */
	private static function percentile( array $samples, float $p ): float {
		sort( $samples );

		return $samples[ (int) min( count( $samples ) - 1, floor( $p * count( $samples ) ) ) ];
	}

	/**
	 * Print a line when AMC_BENCH_REPORT is set.
	 *
	 * @param string $line Line.
	 */
	private static function report( string $line ): void {
		if ( false !== getenv( 'AMC_BENCH_REPORT' ) ) {
			fwrite( STDERR, $line . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		}
	}

	/**
	 * Run one simulated request.
	 *
	 * @param int  $i       Request number.
	 * @param bool $sampled Whether the query sampler is on for it.
	 * @param int  $events  Events the request produces.
	 */
	private function request( int $i, bool $sampled, int $events ): float {
		$buffer = new Buffer();
		$start  = hrtime( true );

		$errors       = new ErrorCapture( $buffer, false );
		$deprecations = new DeprecationCapture( $buffer );
		$sampler      = new QuerySampler( $buffer, static fn (): int => $sampled ? 1 : 2 );
		$log          = new ChangeLog();

		$errors->register_hooks();
		$deprecations->register_hooks();
		$sampler->register_hooks();
		$log->register_hooks();

		for ( $e = 0; $e < $events; $e++ ) {
			if ( 0 === $e % 2 ) {
				$errors->handle( E_WARNING, 'Undefined array key ' . $e, WP_PLUGIN_DIR . '/bench/bench.php', 10 + $e );
			} else {
				$deprecations->on_function( 'old_fn_' . ( $e % 3 ), 'new_fn', '6.0.0' );
			}
		}

		if ( $sampled ) {
			// A typical page: 25 queries, run through the `query` filter.
			for ( $q = 0; $q < 25; $q++ ) {
				$sampler->on_query( "SELECT option_value FROM wp_options WHERE option_name = 'opt_{$i}_{$q}'" );
			}
		}

		$elapsed = ( hrtime( true ) - $start ) / 1_000_000;

		// Tear down (not part of the request cost).
		restore_error_handler();
		remove_filter( 'wp_php_error_message', [ $errors, 'on_fatal_screen' ] );
		remove_action( 'shutdown', [ $buffer, 'flush' ], 999 );
		foreach ( [
			'deprecated_function_run'  => 'on_function',
			'deprecated_argument_run'  => 'on_argument',
			'deprecated_hook_run'      => 'on_hook',
			'deprecated_file_included' => 'on_file',
			'deprecated_class_run'     => 'on_class',
			'doing_it_wrong_run'       => 'on_doing_it_wrong',
		] as $hook => $method ) {
			remove_action( $hook, [ $deprecations, $method ] );
		}
		remove_filter( 'query', [ $sampler, 'on_query' ], PHP_INT_MAX );
		remove_filter( 'log_query_custom_data', [ $sampler, 'on_logged_query' ], PHP_INT_MAX );
		remove_filter( 'upgrader_pre_install', [ $log, 'on_pre_install' ] );
		foreach ( [
			'upgrader_process_complete'  => 'on_upgrade_complete',
			'activated_plugin'           => 'on_activated',
			'deactivated_plugin'         => 'on_deactivated',
			'delete_plugin'              => 'on_delete_plugin',
			'deleted_plugin'             => 'on_deleted_plugin',
			'switch_theme'               => 'on_switch_theme',
			'_core_updated_successfully' => 'on_core_updated',
			'user_register'              => 'on_user_register',
			'delete_user'                => 'on_delete_user',
			'set_user_role'              => 'on_set_user_role',
		] as $hook => $method ) {
			remove_action( $hook, [ $log, $method ] );
		}

		return $elapsed;
	}

	public function test_a_thousand_requests_stay_under_two_milliseconds_and_run_no_queries_before_shutdown(): void {
		global $wpdb;

		$queries_before = $wpdb->num_queries;
		$all            = [];
		$plain          = [];
		$busy           = [];
		$sampled        = [];

		for ( $i = 0; $i < self::REQUESTS; $i++ ) {
			// 99% not sampled; 1 in 10 requests records 8 events, the others none.
			$is_sampled = 0 === $i % 100;
			$events     = 0 === $i % 10 ? 8 : 0;
			$ms         = $this->request( $i, $is_sampled, $events );

			$all[] = $ms;
			if ( $is_sampled ) {
				$sampled[] = $ms;
			} elseif ( $events > 0 ) {
				$busy[] = $ms;
			} else {
				$plain[] = $ms;
			}
		}

		$this->assertSame( $queries_before, $wpdb->num_queries, 'The capture layer runs zero queries before shutdown' );

		$p95 = self::percentile( $all, 0.95 );
		self::report(
			sprintf(
				'capture layer, %d simulated requests (ms): all p50=%.4f p95=%.4f p99=%.4f max=%.4f | no events p95=%.4f | 8 events p50=%.4f p95=%.4f | sampled (25 queries) p50=%.4f max=%.4f',
				self::REQUESTS,
				self::percentile( $all, 0.5 ),
				$p95,
				self::percentile( $all, 0.99 ),
				max( $all ),
				self::percentile( $plain, 0.95 ),
				self::percentile( $busy, 0.5 ),
				self::percentile( $busy, 0.95 ),
				self::percentile( $sampled, 0.5 ),
				max( $sampled )
			)
		);

		$this->assertLessThan( self::BUDGET_MS, $p95, 'p95 of the per-request overhead' );
		// Only 100 and 10 requests fall in these groups, so use the median: a p95 over 10 samples is the maximum, i.e. one noisy neighbour.
		$this->assertLessThan( self::BUDGET_MS, self::percentile( $busy, 0.5 ), 'Requests that record events stay inside the budget too' );
		$this->assertLessThan( self::BUDGET_MS, self::percentile( $sampled, 0.5 ), 'So do the 1% that sample queries' );
	}

	public function test_the_single_shutdown_write_is_one_query_per_table(): void {
		global $wpdb;

		$buffer = new Buffer();
		$errors = new ErrorCapture( $buffer, false );
		for ( $e = 0; $e < 150; $e++ ) {
			$errors->handle( E_WARNING, "w{$e}", WP_PLUGIN_DIR . '/bench/flush.php', $e );
		}
		for ( $q = 0; $q < 40; $q++ ) {
			$buffer->add_query( "SELECT {$q} FROM t{$q}", 'c', 150.0 );
		}

		$before = $wpdb->num_queries;
		$start  = hrtime( true );
		$buffer->flush();
		$ms = ( hrtime( true ) - $start ) / 1_000_000;

		self::report( sprintf( 'shutdown flush of %d entries: %.2f ms, %d queries', 190, $ms, $wpdb->num_queries - $before ) );
		$this->assertSame( 3, $wpdb->num_queries - $before, 'Kill switch option read + one events upsert + one queries upsert' );
	}
}
