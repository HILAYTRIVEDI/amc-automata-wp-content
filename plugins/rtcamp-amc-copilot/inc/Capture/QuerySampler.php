<?php
/**
 * Sampled slow-query capture.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Capture;

use rtCamp\WPPrimitives\Contracts\Interfaces\Registrable;

/**
 * Class QuerySampler
 *
 * One request in SAMPLE_RATE (filter `amc_capture_query_sample_rate`, 0 turns
 * it off) is sampled, decided once when the plugin loads with `mt_rand()`. The
 * other 99% add no hooks at all. `SAVEQUERIES` is never turned on.
 *
 * Timing on a sampled request:
 *
 * - `query` filter: runs just before each query. It closes the previous query
 *   (elapsed = now - its start) and opens the next one. Without SAVEQUERIES
 *   WordPress offers no "after query" hook, so the elapsed time is the time
 *   until the NEXT query starts, an upper bound that also contains PHP work
 *   between two queries. The last query of a request is not measured.
 * - `log_query_custom_data` filter: WordPress only fires it when the host
 *   already defines SAVEQUERIES. Then it carries the exact time, and it
 *   supersedes the estimate for that query.
 *
 * Only queries of at least THRESHOLD_MS (filter `amc_capture_slow_query_ms`)
 * are buffered. Their signature is `md5( normalised SQL | caller )`, and the
 * stored sample is the normalised SQL (literals removed, so no user data).
 *
 * @since 0.1.0
 */
final class QuerySampler implements Registrable {

	/**
	 * Sample one request in this many.
	 */
	public const SAMPLE_RATE = 100;

	/**
	 * Slow-query threshold in milliseconds.
	 */
	public const THRESHOLD_MS = 100.0;

	/**
	 * Query waiting for the next one to give its duration.
	 *
	 * @var array{sql: string, caller: string, start: float}|null
	 */
	private ?array $pending = null;

	/**
	 * Threshold in ms, resolved when sampling is on.
	 *
	 * @var float
	 */
	private float $threshold;

	/**
	 * Dice: returns 1 when the request is sampled.
	 *
	 * @var \Closure(int): int
	 */
	private \Closure $dice;

	/**
	 * Clock in milliseconds.
	 *
	 * @var \Closure(): float
	 */
	private \Closure $clock;

	/**
	 * Caller summary of the query being run.
	 *
	 * @var \Closure(): string
	 */
	private \Closure $caller;

	/**
	 * Constructor.
	 *
	 * @param Buffer|null   $buffer Buffer (default: the request-wide one).
	 * @param \Closure|null $dice   fn( int $rate ): int, 1 = sampled (test seam, default mt_rand).
	 * @param \Closure|null $clock  fn(): float milliseconds (test seam).
	 * @param \Closure|null $caller fn(): string caller summary (test seam).
	 */
	public function __construct(
		private ?Buffer $buffer = null,
		?\Closure $dice = null,
		?\Closure $clock = null,
		?\Closure $caller = null
	) {
		$this->buffer  ??= Buffer::shared();
		$this->threshold = self::THRESHOLD_MS;
		$this->dice      = $dice ?? static fn ( int $rate ): int => mt_rand( 1, $rate ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- Sampling, not security: the cheapest generator is the point.
		$this->clock     = $clock ?? static fn (): float => hrtime( true ) / 1_000_000;
		$this->caller    = $caller ?? static fn (): string => self::default_caller();
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		if ( Buffer::disabled_by_constant() ) {
			return;
		}

		/**
		 * Sample one request in N for slow queries. 0 turns the sampler off.
		 *
		 * Applied when the plugin loads, so add the filter from a must-use plugin or wp-config.
		 *
		 * @param int $rate Default 100.
		 */
		$rate = (int) apply_filters( 'amc_capture_query_sample_rate', self::SAMPLE_RATE );

		if ( $rate < 1 || 1 !== ( $this->dice )( $rate ) ) {
			return;
		}

		/**
		 * Slow-query threshold in milliseconds.
		 *
		 * @param float $ms Default 100.
		 */
		$this->threshold = (float) apply_filters( 'amc_capture_slow_query_ms', self::THRESHOLD_MS );

		add_filter( 'query', [ $this, 'on_query' ], PHP_INT_MAX );
		add_filter( 'log_query_custom_data', [ $this, 'on_logged_query' ], PHP_INT_MAX, 5 );

		$this->buffer->register_flush();
	}

	/**
	 * `query` filter: close the previous query, open this one.
	 *
	 * @param mixed $query SQL.
	 *
	 * @return mixed The SQL, untouched.
	 */
	public function on_query( mixed $query ): mixed {
		$now = ( $this->clock )();

		if ( null !== $this->pending ) {
			$elapsed       = $now - $this->pending['start'];
			$sql           = $this->pending['sql'];
			$caller        = $this->pending['caller'];
			$this->pending = null;

			if ( $elapsed >= $this->threshold ) {
				$this->buffer->add_query( $sql, $caller, $elapsed );
			}
		}

		if ( is_string( $query ) ) {
			$this->pending = [
				'sql'    => $query,
				'caller' => ( $this->caller )(),
				'start'  => $now,
			];
		}

		return $query;
	}

	/**
	 * `log_query_custom_data` filter (only fired when the host defines SAVEQUERIES): exact timing.
	 *
	 * @param mixed  $data    Custom data (returned untouched).
	 * @param string $query   SQL.
	 * @param float  $seconds Duration in seconds.
	 * @param string $stack   Call stack summary.
	 * @param mixed  $start   Query start time (unused).
	 */
	public function on_logged_query( mixed $data, string $query = '', float $seconds = 0.0, string $stack = '', mixed $start = null ): mixed {
		unset( $start );
		$this->pending = null;

		$ms = $seconds * 1000;
		if ( $ms >= $this->threshold ) {
			$this->buffer->add_query( $query, self::shorten( $stack ), $ms );
		}

		return $data;
	}

	/**
	 * Caller of the query being started, without WordPress's filter plumbing.
	 */
	private static function default_caller(): string {
		global $wpdb;

		return self::shorten( method_exists( $wpdb, 'get_caller' ) ? (string) $wpdb->get_caller() : '' );
	}

	/**
	 * Keep the innermost three meaningful frames of a "a, b, c" call summary.
	 *
	 * @param string $stack Call summary, outermost first.
	 */
	public static function shorten( string $stack ): string {
		$frames = array_filter(
			array_map( 'trim', explode( ',', $stack ) ),
			static fn ( string $frame ): bool => '' !== $frame
				&& 1 !== preg_match( '/^(?:apply_filters|WP_Hook->|AMC\\\\Capture\\\\|wpdb->)/', $frame )
		);

		return mb_substr( implode( ', ', array_slice( array_values( $frames ), -3 ) ), 0, 255 );
	}
}
