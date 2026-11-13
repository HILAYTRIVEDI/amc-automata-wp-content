<?php
/**
 * Tests for the slow-query sampler.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Capture;

use AMC\Capture\Buffer;
use AMC\Capture\QuerySampler;
use AMC\Capture\Signature;
use AMC\Storage\QueriesRepository;
use AMC\Tests\TestCase;

/**
 * Class QuerySamplerTest
 */
final class QuerySamplerTest extends TestCase {

	/**
	 * Fake clock in ms.
	 *
	 * @var float
	 */
	private float $now = 0.0;

	/**
	 * Build a sampler that is always sampled, with a fake clock and caller.
	 *
	 * @param Buffer $buffer Buffer.
	 */
	private function sampler( Buffer $buffer ): QuerySampler {
		$sampler = new QuerySampler(
			$buffer,
			static fn (): int => 1,
			fn (): float => $this->now,
			static fn (): string => 'Test->caller'
		);
		$sampler->register_hooks();

		return $sampler;
	}

	public function test_roughly_one_request_in_a_hundred_is_sampled_by_default(): void {
		$sampled = 0;
		$trials  = 6000;

		for ( $i = 0; $i < $trials; $i++ ) {
			$sampler = new QuerySampler( new Buffer() );
			$sampler->register_hooks();

			if ( false !== has_filter( 'query', [ $sampler, 'on_query' ] ) ) {
				++$sampled;
				remove_filter( 'query', [ $sampler, 'on_query' ], PHP_INT_MAX );
				remove_filter( 'log_query_custom_data', [ $sampler, 'on_logged_query' ], PHP_INT_MAX );
			}
		}

		$this->assertGreaterThan( 20, $sampled, 'About 60 expected out of 6000' );
		$this->assertLessThan( 130, $sampled );
	}

	public function test_dice_decides_and_unsampled_requests_add_no_hooks(): void {
		$sampler = new QuerySampler( new Buffer(), static fn (): int => 2 );
		$sampler->register_hooks();

		$this->assertFalse( has_filter( 'query', [ $sampler, 'on_query' ] ) );
		$this->assertFalse( has_filter( 'log_query_custom_data', [ $sampler, 'on_logged_query' ] ) );
	}

	public function test_sampled_request_adds_both_filters(): void {
		$sampler = new QuerySampler( new Buffer(), static fn (): int => 1 );
		$sampler->register_hooks();

		$this->assertSame( PHP_INT_MAX, has_filter( 'query', [ $sampler, 'on_query' ] ) );
		$this->assertSame( PHP_INT_MAX, has_filter( 'log_query_custom_data', [ $sampler, 'on_logged_query' ] ) );
	}

	public function test_rate_is_filterable_and_zero_turns_the_sampler_off(): void {
		$rates = [];
		add_filter( 'amc_capture_query_sample_rate', static fn (): int => 0 );

		$sampler = new QuerySampler(
			new Buffer(),
			static function ( int $rate ) use ( &$rates ): int {
				$rates[] = $rate;
				return 1;
			}
		);
		$sampler->register_hooks();

		$this->assertFalse( has_filter( 'query', [ $sampler, 'on_query' ] ) );
		$this->assertSame( [], $rates, 'The dice is not even thrown' );

		remove_all_filters( 'amc_capture_query_sample_rate' );
		add_filter( 'amc_capture_query_sample_rate', static fn (): int => 7 );
		$sampler = new QuerySampler(
			new Buffer(),
			static function ( int $rate ) use ( &$rates ): int {
				$rates[] = $rate;
				return 1;
			}
		);
		$sampler->register_hooks();

		$this->assertSame( [ 7 ], $rates );
	}

	public function test_only_queries_over_100_ms_are_kept(): void {
		$buffer  = new Buffer();
		$sampler = $this->sampler( $buffer );

		$this->now = 0.0;
		$sampler->on_query( 'SELECT fast' );
		$this->now = 99.0;
		$sampler->on_query( 'SELECT slow' );
		$this->assertSame( 0, $buffer->size(), '99 ms is not slow' );

		$this->now = 199.0;
		$sampler->on_query( 'SELECT next' );
		$this->assertSame( 1, $buffer->size(), '100 ms is slow' );
	}

	public function test_query_filter_returns_the_sql_untouched(): void {
		$sampler = $this->sampler( new Buffer() );

		$this->assertSame( 'SELECT 1', $sampler->on_query( 'SELECT 1' ) );
		$this->assertSame( [ 'not sql' ], $sampler->on_query( [ 'not sql' ] ) );
	}

	public function test_slow_query_is_stored_with_a_signature_from_the_normalised_sql_and_the_caller(): void {
		$buffer  = new Buffer();
		$sampler = $this->sampler( $buffer );

		$this->now = 0.0;
		$sampler->on_query( "SELECT * FROM wp_options WHERE option_name = 'secret@example.com'" );
		$this->now = 400.0;
		$sampler->on_query( 'SELECT 1' );
		$buffer->drain();

		$rows = array_values( array_filter( ( new QueriesRepository() )->grouped( gmdate( 'Y-m-d H:00:00', time() - HOUR_IN_SECONDS ) ), static fn ( array $row ): bool => 'Test->caller' === $row['caller'] ) );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'SELECT * FROM wp_options WHERE option_name = ?', $rows[0]['sql_sample'] );
		$this->assertSame( md5( Signature::sql( "SELECT * FROM wp_options WHERE option_name = 'x'" ) . '|Test->caller' ), $rows[0]['signature'] );
		$this->assertEqualsWithDelta( 400.0, $rows[0]['max_ms'], 0.001 );
	}

	public function test_the_last_query_of_a_request_is_not_guessed(): void {
		$buffer  = new Buffer();
		$sampler = $this->sampler( $buffer );

		$this->now = 0.0;
		$sampler->on_query( 'SELECT last' );
		$this->now = 10000.0;

		$this->assertSame( 0, $buffer->size() );
	}

	public function test_savequeries_timing_is_exact_and_supersedes_the_estimate(): void {
		$buffer  = new Buffer();
		$sampler = $this->sampler( $buffer );

		$this->now = 0.0;
		$sampler->on_query( 'SELECT exact' );
		$this->assertSame( 'data', $sampler->on_logged_query( 'data', 'SELECT exact', 0.25, 'a, b', 0.0 ) );

		$this->now = 5000.0;
		$sampler->on_query( 'SELECT after' );

		$this->assertSame( 1, $buffer->size(), 'Counted once, with the exact time, not again by the gap' );
	}

	public function test_fast_logged_query_is_ignored(): void {
		$buffer  = new Buffer();
		$sampler = $this->sampler( $buffer );

		$sampler->on_logged_query( [], 'SELECT quick', 0.01, 'a' );

		$this->assertSame( 0, $buffer->size() );
	}

	public function test_threshold_is_filterable(): void {
		add_filter( 'amc_capture_slow_query_ms', static fn (): float => 10.0 );
		$buffer  = new Buffer();
		$sampler = $this->sampler( $buffer );

		$this->now = 0.0;
		$sampler->on_query( 'SELECT a' );
		$this->now = 15.0;
		$sampler->on_query( 'SELECT b' );

		$this->assertSame( 1, $buffer->size() );
	}

	public function test_shorten_drops_wordpress_plumbing_and_keeps_the_innermost_frames(): void {
		$stack = 'require(\'wp-blog-header.php\'), require_once(\'wp-load.php\'), WP_Query->query, WP_Query->get_posts, apply_filters, WP_Hook->apply_filters, AMC\Capture\QuerySampler->on_query';

		$this->assertSame( 'require_once(\'wp-load.php\'), WP_Query->query, WP_Query->get_posts', QuerySampler::shorten( $stack ) );
	}

	public function test_sampling_runs_no_queries_of_its_own(): void {
		global $wpdb;
		$sampler = $this->sampler( new Buffer() );
		$before  = $wpdb->num_queries;

		for ( $i = 0; $i < 20; $i++ ) {
			$this->now += 5.0;
			$sampler->on_query( "SELECT {$i}" );
		}

		$this->assertSame( $before, $wpdb->num_queries );
	}
}
