<?php
/**
 * Check: perf.response (server response time of the smoke URLs).
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
use AMC\Storage\RunsRepository;

/**
 * Class ResponseTime
 *
 * Makes no requests of its own: it reads the response times that the latest
 * `smoke.urls` run stored (`urls[].history` and `urls[].daily`).
 *
 * - current  = average response time of a URL's last 24 smoke checks (at least 3).
 * - baseline = average over the 28 days before today (at least 24 samples).
 * A URL is "slower" when current is 20% or more above baseline AND at least
 * 50 ms above it (so a 40 ms page going to 50 ms is not an alert).
 *
 * Result::$data shape (Performance screen, report snapshot):
 *   checked_at     string  UTC ISO 8601.
 *   threshold_pct  int     20.
 *   avg_ms         int|null  Average current response time across URLs.
 *   urls           list<array{url: string, current_ms: int|null, baseline_ms: int|null, change_pct: float|null, slower: bool, samples: int, baseline_samples: int}>
 *   trend          list<array{date: 'Y-m-d', avg_ms: int, url_count: int}>  Daily average over the last 30 days, oldest first.
 *
 * Status rules: no smoke data -> INFO (partial: nothing resolves); a slower
 * URL -> WARN; otherwise PASS.
 *
 * Findings (digest only, never above MEDIUM): one per slower URL, dedupe key
 * `slow:<md5(url)>`; MEDIUM when 50% or more slower, LOW otherwise.
 *
 * @since 0.1.0
 */
final class ResponseTime implements Check {

	/**
	 * Percent slower than baseline that counts as slow.
	 */
	public const THRESHOLD_PCT = 20;

	/**
	 * Smallest slowdown in milliseconds worth reporting.
	 */
	private const MIN_DELTA_MS = 50;

	/**
	 * Latest smoke data: callable( int $blog_id ): array|null.
	 *
	 * @var callable
	 */
	private $smoke_source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $smoke_source Test seam; default the latest smoke.urls run's data.
	 */
	public function __construct( ?callable $smoke_source = null ) {
		$this->smoke_source = $smoke_source ?? static function ( int $blog_id ): ?array {
			$run = ( new RunsRepository() )->latest( 'smoke.urls', $blog_id );

			return null === $run ? null : (array) $run['data'];
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'perf.response';
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
		return __( 'Response time', 'rtcamp-amc-copilot' );
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
		$smoke = ( $this->smoke_source )( $ctx->blog_id );
		$data  = [
			'checked_at'    => gmdate( 'c', $ctx->now->getTimestamp() ),
			'threshold_pct' => self::THRESHOLD_PCT,
			'avg_ms'        => null,
			'urls'          => [],
			'trend'         => [],
		];

		if ( ! is_array( $smoke ) || [] === (array) ( $smoke['urls'] ?? [] ) ) {
			return Result::info( __( 'There is no smoke check data yet, so response times cannot be compared.', 'rtcamp-amc-copilot' ), $data )->partial();
		}

		$today     = gmdate( 'Y-m-d', $ctx->now->getTimestamp() );
		$baseline0 = gmdate( 'Y-m-d', $ctx->now->getTimestamp() - 28 * DAY_IN_SECONDS );
		$trend0    = gmdate( 'Y-m-d', $ctx->now->getTimestamp() - 29 * DAY_IN_SECONDS );
		$findings  = [];
		$currents  = [];
		$days      = [];

		foreach ( (array) $smoke['urls'] as $state ) {
			if ( ! is_array( $state ) || ! isset( $state['url'] ) ) {
				continue;
			}

			$url = (string) $state['url'];

			$recent = array_values(
				array_filter(
					array_map( static fn ( $h ): int => is_array( $h ) ? (int) ( $h['ms'] ?? 0 ) : 0, array_slice( (array) ( $state['history'] ?? [] ), -24 ) ),
					static fn ( int $ms ): bool => $ms > 0
				)
			);

			$sum = 0;
			$n   = 0;
			foreach ( (array) ( $state['daily'] ?? [] ) as $date => $bucket ) {
				$date = (string) $date;

				if ( $date >= $trend0 && is_array( $bucket ) && (int) ( $bucket['ms_n'] ?? 0 ) > 0 ) {
					$days[ $date ]['sum']  = ( $days[ $date ]['sum'] ?? 0 ) + (int) $bucket['ms_sum'];
					$days[ $date ]['n']    = ( $days[ $date ]['n'] ?? 0 ) + (int) $bucket['ms_n'];
					$days[ $date ]['urls'] = ( $days[ $date ]['urls'] ?? 0 ) + 1;
				}

				if ( $date >= $baseline0 && $date < $today && is_array( $bucket ) ) {
					$sum += (int) ( $bucket['ms_sum'] ?? 0 );
					$n   += (int) ( $bucket['ms_n'] ?? 0 );
				}
			}

			$current  = count( $recent ) >= 3 ? (int) round( array_sum( $recent ) / count( $recent ) ) : null;
			$baseline = $n >= 24 ? (int) round( $sum / $n ) : null;
			$change   = null !== $current && null !== $baseline && $baseline > 0 ? round( ( $current - $baseline ) / $baseline * 100, 1 ) : null;
			$slower   = null !== $change && $change >= self::THRESHOLD_PCT && ( $current - $baseline ) >= self::MIN_DELTA_MS;

			$data['urls'][] = [
				'url'              => $url,
				'current_ms'       => $current,
				'baseline_ms'      => $baseline,
				'change_pct'       => $change,
				'slower'           => $slower,
				'samples'          => count( $recent ),
				'baseline_samples' => $n,
			];

			if ( null !== $current ) {
				$currents[] = $current;
			}

			if ( $slower ) {
				$findings[] = new Finding(
					'slow:' . md5( $url ),
					$change >= 50 ? Severity::MEDIUM : Severity::LOW,
					sprintf(
						/* translators: 1: URL, 2: Percent slower, 3: Baseline in ms, 4: Current in ms. */
						__( '%1$s is %2$s%% slower than its 4-week average (%3$d ms to %4$d ms)', 'rtcamp-amc-copilot' ),
						$url,
						number_format_i18n( (float) $change, 0 ),
						$baseline,
						$current
					),
					[
						'url'         => $url,
						'current_ms'  => $current,
						'baseline_ms' => $baseline,
						'change_pct'  => $change,
					]
				);
			}
		}

		ksort( $days );
		foreach ( $days as $date => $day ) {
			$data['trend'][] = [
				'date'      => (string) $date,
				'avg_ms'    => (int) round( $day['sum'] / $day['n'] ),
				'url_count' => (int) $day['urls'],
			];
		}

		$data['avg_ms'] = [] === $currents ? null : (int) round( array_sum( $currents ) / count( $currents ) );

		if ( [] !== $findings ) {
			return Result::warn(
				sprintf(
					/* translators: %d: Number of URLs. */
					_n( '%d URL is at least 20%% slower than its 4-week average.', '%d URLs are at least 20%% slower than their 4-week average.', count( $findings ), 'rtcamp-amc-copilot' ),
					count( $findings )
				),
				$data,
				$findings
			);
		}

		return Result::pass( __( 'Response times are in line with the 4-week average.', 'rtcamp-amc-copilot' ), $data );
	}
}
