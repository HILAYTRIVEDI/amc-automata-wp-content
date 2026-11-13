<?php
/**
 * Route: /performance.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Rest;

use AMC\Storage\QueriesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class PerformanceController
 *
 * Owner: lane B2. `checks` and `slow_queries` work today; `response_trend`
 * is a placeholder ([]) until B2 defines perf.response data.
 *
 * GET /amc/v1/performance?days=30 → {
 *   checks: { 'perf.response' | 'perf.database' | 'perf.cache' | 'perf.slow_queries': { run, data } | null },
 *   response_trend: list<{ date: 'Y-m-d', avg_ms: float, url_count: int }>,   B2 fills
 *   slow_queries: list<{ signature, sql_sample, caller, count, max_ms, avg_ms, last_seen }>
 * }
 *
 * @since 0.1.0
 */
final class PerformanceController extends AbstractController {

	/**
	 * Owning lane.
	 */
	public const OWNER = 'B2';

	/**
	 * Check ids shown on this screen.
	 */
	public const CHECK_IDS = [ 'perf.response', 'perf.database', 'perf.cache', 'perf.slow_queries' ];

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		$this->route(
			'/performance',
			self::readable(),
			[ $this, 'get_screen' ],
			[ $this, 'manage' ],
			[
				'days' => [
					'type'    => 'integer',
					'default' => 30,
					'minimum' => 1,
					'maximum' => 90,
				],
			]
		);
	}

	/**
	 * GET /performance.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_screen( WP_REST_Request $request ): WP_REST_Response {
		$since   = gmdate( 'Y-m-d H:i:s', time() - (int) $request->get_param( 'days' ) * DAY_IN_SECONDS );
		$queries = array_map(
			static function ( array $row ): array {
				$row['count']     = (int) $row['count'];
				$row['max_ms']    = round( (float) $row['max_ms'], 1 );
				$row['avg_ms']    = round( (float) $row['avg_ms'], 1 );
				$row['last_seen'] = self::iso( $row['last_seen'] );
				return $row;
			},
			( new QueriesRepository() )->grouped( $since )
		);

		return $this->stub(
			[
				'checks'         => $this->latest_results( self::CHECK_IDS ),
				'response_trend' => [],
				'slow_queries'   => $queries,
			],
			self::OWNER
		);
	}
}
