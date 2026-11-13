<?php
/**
 * Check: perf.slow_queries (sampled slow queries by caller).
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks\Performance;

use AMC\Checks\Check;
use AMC\Checks\Context;
use AMC\Checks\Finding;
use AMC\Checks\Group;
use AMC\Checks\Result;
use AMC\Checks\Schedule;
use AMC\Checks\Severity;
use AMC\Storage\QueriesRepository;

/**
 * Class SlowQueries
 *
 * Summarises `amc_queries` (queries over 100 ms, sampled on 1 request in 100
 * by the capture layer) for the last 24 hours, grouped by CALLER. Reads only.
 *
 * Result::$data shape:
 *   since     string  UTC ISO 8601: start of the 24 h window.
 *   queries   int     Sampled slow queries in the window.
 *   callers   list<array{caller: string, count: int, signatures: int, max_ms: float, avg_ms: float, sample: string, last_seen: string}>
 *             Busiest first, at most 25. `sample` is the SQL of the caller's most frequent query.
 *
 * Status rules: no samples -> PASS; a caller that is slow enough to report -> WARN; samples but none that bad -> PASS.
 *
 * Finding rule: a caller with a query of 500 ms or more, or 20 or more sampled
 * slow queries in the window. Digest only: LOW, or MEDIUM from 1000 ms. Dedupe
 * key `caller:<md5(caller)>`.
 *
 * @since 0.1.0
 */
final class SlowQueries implements Check {

	/**
	 * Slowest-query threshold that earns a finding, in ms.
	 */
	private const SLOW_MS = 500.0;

	/**
	 * Slowest-query threshold for MEDIUM, in ms.
	 */
	private const VERY_SLOW_MS = 1000.0;

	/**
	 * Sampled-query count that earns a finding.
	 */
	private const FREQUENT = 20;

	/**
	 * Callers kept in the data.
	 */
	private const MAX_CALLERS = 25;

	/**
	 * Rows: callable( string $since, int $blog_id ): list<array>  (QueriesRepository::grouped()).
	 *
	 * @var callable
	 */
	private $queries_source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $queries_source Test seam; default QueriesRepository::grouped().
	 */
	public function __construct( ?callable $queries_source = null ) {
		$this->queries_source = $queries_source ?? static fn ( string $since, int $blog_id ): array => ( new QueriesRepository() )->grouped( $since, $blog_id, 500 );
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'perf.slow_queries';
	}

	/**
	 * {@inheritDoc}
	 */
	public function group(): Group {
		return Group::PERFORMANCE;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Slow queries', 'rtcamp-amc-copilot' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function schedule(): Schedule {
		return Schedule::HOURLY;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Context $ctx Run context.
	 */
	public function run( Context $ctx ): Result {
		$since = $ctx->now->getTimestamp() - DAY_IN_SECONDS;
		$rows  = ( $this->queries_source )( gmdate( 'Y-m-d H:i:s', $since ), $ctx->blog_id );

		/** @var array<string, array<string, mixed>> $callers */
		$callers = [];
		$total   = 0;

		foreach ( $rows as $row ) {
			$caller = '' === trim( (string) $row['caller'] ) ? 'unknown' : (string) $row['caller'];
			$count  = (int) $row['count'];
			$total += $count;

			if ( ! isset( $callers[ $caller ] ) ) {
				$callers[ $caller ] = [
					'caller'     => $caller,
					'count'      => 0,
					'signatures' => 0,
					'max_ms'     => 0.0,
					'weighted'   => 0.0,
					'sample'     => '',
					'top'        => -1,
					'last_seen'  => (string) $row['last_seen'],
				];
			}

			$entry                = &$callers[ $caller ];
			$entry['count']      += $count;
			$entry['signatures'] += 1;
			$entry['max_ms']      = max( $entry['max_ms'], (float) $row['max_ms'] );
			$entry['weighted']   += (float) $row['avg_ms'] * $count;
			$entry['last_seen']   = max( $entry['last_seen'], (string) $row['last_seen'] );

			if ( $count > $entry['top'] ) {
				$entry['top']    = $count;
				$entry['sample'] = mb_substr( (string) $row['sql_sample'], 0, 300 );
			}
			unset( $entry );
		}

		usort( $callers, static fn ( array $a, array $b ): int => $b['count'] <=> $a['count'] );

		$data = [
			'since'   => gmdate( 'c', $since ),
			'queries' => $total,
			'callers' => [],
		];

		$findings = [];

		foreach ( array_slice( $callers, 0, self::MAX_CALLERS ) as $entry ) {
			$item = [
				'caller'     => $entry['caller'],
				'count'      => $entry['count'],
				'signatures' => $entry['signatures'],
				'max_ms'     => round( $entry['max_ms'], 1 ),
				'avg_ms'     => round( $entry['weighted'] / max( 1, $entry['count'] ), 1 ),
				'sample'     => $entry['sample'],
				'last_seen'  => $entry['last_seen'],
			];

			$data['callers'][] = $item;

			if ( $entry['max_ms'] >= self::SLOW_MS || $entry['count'] >= self::FREQUENT ) {
				$findings[] = new Finding(
					'caller:' . md5( $entry['caller'] ),
					$entry['max_ms'] >= self::VERY_SLOW_MS ? Severity::MEDIUM : Severity::LOW,
					sprintf(
						/* translators: 1: Caller, 2: Number of sampled slow queries, 3: Slowest query in ms. */
						__( 'Slow queries from %1$s: %2$d sampled, slowest %3$d ms', 'rtcamp-amc-copilot' ),
						$entry['caller'],
						$entry['count'],
						(int) round( $entry['max_ms'] )
					),
					$item
				);
			}
		}

		if ( [] !== $findings ) {
			return Result::warn(
				sprintf(
					/* translators: %d: Number of callers. */
					_n( '%d caller runs slow queries.', '%d callers run slow queries.', count( $findings ), 'rtcamp-amc-copilot' ),
					count( $findings )
				),
				$data,
				$findings
			);
		}

		return Result::pass(
			0 === $total
				? __( 'No slow queries were sampled in the last 24 hours.', 'rtcamp-amc-copilot' )
				/* translators: %d: Number of sampled slow queries. */
				: sprintf( _n( '%d slow query sampled, none bad enough to report.', '%d slow queries sampled, none bad enough to report.', $total, 'rtcamp-amc-copilot' ), $total ),
			$data
		);
	}
}
