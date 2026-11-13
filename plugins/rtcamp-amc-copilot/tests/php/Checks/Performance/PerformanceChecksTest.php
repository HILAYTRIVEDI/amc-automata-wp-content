<?php
/**
 * Tests for perf.response and perf.slow_queries.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Checks\Performance;

use AMC\Checks\Context;
use AMC\Checks\Group;
use AMC\Checks\Performance\ResponseTime;
use AMC\Checks\Performance\SlowQueries;
use AMC\Checks\Schedule;
use AMC\Checks\Severity;
use AMC\Checks\Status;
use AMC\Storage\QueriesRepository;
use AMC\Tests\TestCase;

/**
 * Class PerformanceChecksTest
 */
final class PerformanceChecksTest extends TestCase {

	private const NOW = '2026-11-14 12:00:00';

	/**
	 * Smoke state for one URL: 28 baseline days at `$baseline_ms` (24 checks a day) and a recent history at `$recent_ms`.
	 *
	 * @param string $url         URL.
	 * @param int    $baseline_ms Baseline average.
	 * @param int    $recent_ms   Recent average.
	 * @param int    $days        Baseline days.
	 *
	 * @return array<string, mixed>
	 */
	private function state( string $url, int $baseline_ms, int $recent_ms, int $days = 28 ): array {
		$daily = [];
		for ( $i = 1; $i <= $days; $i++ ) {
			$daily[ gmdate( 'Y-m-d', strtotime( self::NOW . ' UTC' ) - $i * DAY_IN_SECONDS ) ] = [
				'n'       => 24,
				'ok'      => 24,
				'blocked' => 0,
				'ms_sum'  => 24 * $baseline_ms,
				'ms_n'    => 24,
			];
		}

		$history = [];
		for ( $i = 0; $i < 24; $i++ ) {
			$history[] = [
				'at' => '2026-11-14T00:00:00+00:00',
				'ms' => $recent_ms,
				'ok' => true,
			];
		}

		return [
			'url'     => $url,
			'history' => $history,
			'daily'   => $daily,
		];
	}

	/**
	 * Run perf.response over smoke states.
	 *
	 * @param list<array<string, mixed>>|null $states Smoke URL states (null = no smoke run).
	 */
	private function run_response( ?array $states ): \AMC\Checks\Result {
		$check = new ResponseTime( static fn (): ?array => null === $states ? null : [ 'urls' => $states ] );

		return $check->run( Context::for_tests( null, self::NOW ) );
	}

	public function test_metadata(): void {
		$expected = [
			[ new ResponseTime(), 'perf.response' ],
			[ new SlowQueries(), 'perf.slow_queries' ],
		];

		foreach ( $expected as [ $check, $id ] ) {
			$this->assertSame( $id, $check->id() );
			$this->assertSame( Group::PERFORMANCE, $check->group() );
			$this->assertSame( Schedule::HOURLY, $check->schedule() );
			$this->assertNotSame( '', $check->label() );
		}
	}

	public function test_no_smoke_data_is_partial_info(): void {
		$result = $this->run_response( null );

		$this->assertSame( Status::INFO, $result->status );
		$this->assertFalse( $result->complete );
		$this->assertSame( [], $result->findings );
	}

	public function test_twenty_percent_slower_than_the_four_week_average_raises_a_finding(): void {
		$result = $this->run_response( [ $this->state( 'https://example.test/', 500, 600 ) ] );

		$this->assertSame( Status::WARN, $result->status );
		$this->assertCount( 1, $result->findings );
		$this->assertSame( 'slow:' . md5( 'https://example.test/' ), $result->findings[0]->dedupe_key );
		$this->assertSame( Severity::LOW, $result->findings[0]->severity );
		$this->assertSame( 20.0, $result->data['urls'][0]['change_pct'] );
		$this->assertSame( 500, $result->data['urls'][0]['baseline_ms'] );
		$this->assertSame( 600, $result->data['urls'][0]['current_ms'] );
		$this->assertSame( 600, $result->data['avg_ms'] );
	}

	public function test_fifty_percent_slower_is_medium(): void {
		$result = $this->run_response( [ $this->state( 'https://example.test/', 400, 700 ) ] );

		$this->assertSame( Severity::MEDIUM, $result->findings[0]->severity );
	}

	public function test_just_under_the_threshold_and_tiny_absolute_changes_do_not_alert(): void {
		$under = $this->run_response( [ $this->state( 'https://example.test/', 500, 599 ) ] );
		$tiny  = $this->run_response( [ $this->state( 'https://example.test/', 40, 60 ) ] );

		$this->assertSame( Status::PASS, $under->status );
		$this->assertSame( [], $under->findings );
		$this->assertSame( [], $tiny->findings, '+50% but only 20 ms' );
	}

	public function test_without_enough_baseline_there_is_no_comparison(): void {
		$result = $this->run_response( [ $this->state( 'https://example.test/', 100, 900, 0 ) ] );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertNull( $result->data['urls'][0]['baseline_ms'] );
		$this->assertNull( $result->data['urls'][0]['change_pct'] );
		$this->assertSame( [], $result->findings );
	}

	public function test_trend_is_a_daily_average_over_the_urls(): void {
		$result = $this->run_response(
			[
				$this->state( 'https://a.test/', 400, 400, 3 ),
				$this->state( 'https://b.test/', 600, 600, 3 ),
			]
		);

		$this->assertCount( 3, $result->data['trend'] );
		$this->assertSame(
			[
				'date'      => '2026-11-11',
				'avg_ms'    => 500,
				'url_count' => 2,
			],
			$result->data['trend'][0],
			'Oldest first'
		);
		$this->assertSame( '2026-11-13', $result->data['trend'][2]['date'] );
	}

	public function test_slow_queries_group_by_caller_and_flag_the_bad_ones(): void {
		$rows   = $this->fixture( 'perf/queries' );
		$result = ( new SlowQueries( static fn (): array => $rows ) )->run( Context::for_tests( null, self::NOW ) );

		$this->assertSame( Status::WARN, $result->status );
		$this->assertSame( 32, $result->data['queries'] );
		$this->assertSame( [ 'theme:child', 'plugin:slow-seo', 'core' ], array_column( $result->data['callers'], 'caller' ) );

		$seo = $result->data['callers'][1];
		$this->assertSame( 6, $seo['count'] );
		$this->assertSame( 2, $seo['signatures'] );
		$this->assertSame( 900.0, $seo['max_ms'] );
		$this->assertSame( 400.0, $seo['avg_ms'], '(4 x 500 + 2 x 200) / 6' );
		$this->assertStringContainsString( 'seo', $seo['sample'] );

		$by_key = [];
		foreach ( $result->findings as $finding ) {
			$by_key[ $finding->dedupe_key ] = $finding;
		}
		$this->assertCount( 2, $by_key, 'core has one quick query and is not reported' );
		$this->assertSame( Severity::LOW, $by_key[ 'caller:' . md5( 'plugin:slow-seo' ) ]->severity, '900 ms < 1000 ms' );
		$this->assertArrayHasKey( 'caller:' . md5( 'theme:child' ), $by_key, '25 samples is frequent' );
	}

	public function test_a_very_slow_query_is_medium_and_no_samples_pass(): void {
		$rows = [
			[
				'signature'  => 'x',
				'sql_sample' => 'SELECT SLEEP(2)',
				'caller'     => '',
				'count'      => 1,
				'max_ms'     => 2500.0,
				'avg_ms'     => 2500.0,
				'last_seen'  => '2026-11-14 11:00:00',
			],
		];

		$slow = ( new SlowQueries( static fn (): array => $rows ) )->run( Context::for_tests( null, self::NOW ) );
		$this->assertSame( Severity::MEDIUM, $slow->findings[0]->severity );
		$this->assertSame( 'unknown', $slow->data['callers'][0]['caller'] );

		$none = ( new SlowQueries( static fn (): array => [] ) )->run( Context::for_tests( null, self::NOW ) );
		$this->assertSame( Status::PASS, $none->status );
		$this->assertSame( [], $none->findings );
	}

	public function test_slow_queries_default_source_reads_the_table_for_the_last_24_hours(): void {
		( new QueriesRepository() )->upsert_many(
			[
				[
					'signature'  => md5( 'q' ),
					'sql_sample' => 'SELECT 1',
					'caller'     => 'plugin:real',
					'hour'       => gmdate( 'Y-m-d H:00:00' ),
					'count'      => 3,
					'max_ms'     => 700.0,
					'avg_ms'     => 400.0,
				],
			]
		);

		$result = ( new SlowQueries() )->run( Context::for_tests() );

		$this->assertSame( 'plugin:real', $result->data['callers'][0]['caller'] );
		$this->assertCount( 1, $result->findings );
	}
}
