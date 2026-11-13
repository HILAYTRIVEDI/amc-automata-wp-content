<?php
/**
 * Daily retention job.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Storage;

use AMC\Core\KillSwitch;
use AMC\Core\Settings;
use AMC\Runner\Lock;
use rtCamp\WPPrimitives\Contracts\Interfaces\Registrable;

/**
 * Class Retention
 *
 * Cron event `amc_retention`, daily, scheduled on `amc_activate` and cleared on
 * `amc_deactivate`. Each run takes `Lock::named( 'retention' )`, then for each
 * table calls `delete_older_than( column, cutoff, 1000 )` until a batch returns
 * less than 1,000 rows or the 20 s budget (shared by the whole run) is spent.
 * When the budget runs out with work left, a single follow-up event is
 * scheduled five minutes later.
 *
 * | Table | Column | Cut-off |
 * | --- | --- | --- |
 * | runs, events, changes, snapshots | created_at, hour, created_at, taken_at | `retention_months` (13) |
 * | findings | resolved_at | `retention_months` after resolution (open findings are never deleted) |
 * | queries | hour | 90 days |
 *
 * The kill switch stops the job.
 *
 * @since 0.1.0
 */
final class Retention implements Registrable {

	/**
	 * Cron hook.
	 */
	public const HOOK = 'amc_retention';

	/**
	 * Rows deleted per statement.
	 */
	public const BATCH_SIZE = 1000;

	/**
	 * Seconds one run may spend deleting.
	 */
	public const TIME_LIMIT = 20.0;

	/**
	 * Seconds before a follow-up run when work is left.
	 */
	public const FOLLOW_UP_DELAY = 300;

	/**
	 * Clock in seconds.
	 *
	 * @var \Closure(): float
	 */
	private \Closure $clock;

	/**
	 * Deleter: fn( AbstractRepository $repo, string $column, string $cutoff, int $limit ): int.
	 *
	 * @var \Closure
	 */
	private \Closure $deleter;

	/**
	 * Constructor.
	 *
	 * @param float         $time_limit Seconds per run.
	 * @param int           $batch_size Rows per delete.
	 * @param \Closure|null $clock      fn(): float seconds (test seam).
	 * @param \Closure|null $deleter    Delete step (test seam, default: the repository method).
	 */
	public function __construct(
		private readonly float $time_limit = self::TIME_LIMIT,
		private readonly int $batch_size = self::BATCH_SIZE,
		?\Closure $clock = null,
		?\Closure $deleter = null
	) {
		$this->clock   = $clock ?? static fn (): float => microtime( true );
		$this->deleter = $deleter ?? static fn ( AbstractRepository $repo, string $column, string $cutoff, int $limit ): int => $repo->delete_older_than( $column, $cutoff, $limit );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( self::HOOK, [ $this, 'on_cron' ] );
		add_action( 'amc_activate', [ self::class, 'schedule' ] );
		add_action( 'amc_deactivate', [ self::class, 'unschedule' ] );

		// Self-heal a missing event, but never on front-end requests.
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) ) {
			add_action( 'init', [ self::class, 'schedule' ] );
		}
	}

	/**
	 * Schedule the daily event (next 03:00 UTC).
	 */
	public static function schedule(): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			$next = strtotime( 'tomorrow 03:00:00 UTC' );
			wp_schedule_event( false === $next ? time() + HOUR_IN_SECONDS : $next, 'daily', self::HOOK );
		}
	}

	/**
	 * Clear the event.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Cron callback.
	 */
	public function on_cron(): void {
		$this->run();
	}

	/**
	 * Delete expired rows.
	 *
	 * @param int|null $now Unix time for the cut-offs (test seam).
	 *
	 * @return array<string, int> Table key => rows deleted. Empty when skipped (kill switch, lock held).
	 */
	public function run( ?int $now = null ): array {
		if ( KillSwitch::is_active() ) {
			return [];
		}

		$lock = Lock::named( 'retention' );
		if ( ! $lock->acquire() ) {
			return [];
		}

		$deleted    = [];
		$incomplete = false;

		try {
			$started = ( $this->clock )();

			foreach ( $this->targets( $now ?? time() ) as $key => [ $repository, $column, $cutoff ] ) {
				$deleted[ $key ] = 0;

				do {
					if ( ( $this->clock )() - $started >= $this->time_limit ) {
						$incomplete = true;
						break 2;
					}

					$batch            = ( $this->deleter )( $repository, $column, $cutoff, $this->batch_size );
					$deleted[ $key ] += $batch;
				} while ( $batch >= $this->batch_size );
			}
		} finally {
			$lock->release();
		}

		if ( $incomplete && false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + self::FOLLOW_UP_DELAY, self::HOOK );
		}

		return $deleted;
	}

	/**
	 * Tables, columns and cut-offs.
	 *
	 * @param int $now Unix time.
	 *
	 * @return array<string, array{0: AbstractRepository, 1: string, 2: string}>
	 */
	private function targets( int $now ): array {
		$months = max( 1, (int) Settings::get_value( 'retention_months' ) );
		$clock  = new \DateTimeImmutable( '@' . $now );
		$long   = $clock->modify( "-{$months} months" )->format( 'Y-m-d H:i:s' );
		$short  = $clock->modify( '-' . QueriesRepository::RETENTION_DAYS . ' days' )->format( 'Y-m-d H:i:s' );

		return [
			'runs'      => [ new RunsRepository(), 'created_at', $long ],
			'findings'  => [ new FindingsRepository(), 'resolved_at', $long ],
			'events'    => [ new EventsRepository(), 'hour', $long ],
			'queries'   => [ new QueriesRepository(), 'hour', $short ],
			'changes'   => [ new ChangesRepository(), 'created_at', $long ],
			'snapshots' => [ new SnapshotsRepository(), 'taken_at', $long ],
		];
	}
}
