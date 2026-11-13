<?php
/**
 * WP-Cron scheduler: per-check intervals, catch-up once, oldest first.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Runner;

use AMC\Checks\Registry;
use AMC\Checks\Schedule;
use AMC\Core\Installer;
use AMC\Core\KillSwitch;
use AMC\Core\Settings;
use AMC\Storage\RunsRepository;
use rtCamp\WPPrimitives\Contracts\Interfaces\Registrable;

/**
 * Class Scheduler
 *
 * Design: ONE recurring WP-Cron event (`amc_scheduler_tick`, every 5
 * minutes) instead of one event per check. Each tick:
 *
 *   1. Stops if the kill switch is on.
 *   2. Upgrades the schema if needed; marks crashed 'running' rows as 'error'.
 *   3. Runs queued "Run now" rows, oldest first.
 *   4. Works out which checks are due: due_at = last run + effective
 *      interval (Settings override or Check::schedule()). A check that
 *      missed several intervals is due ONCE (catch-up once). Checks run in
 *      order of due_at, oldest first.
 *   5. Stops starting new checks once the time budget (25 s, filter
 *      `amc_scheduler_time_budget`) is used; the rest run next tick.
 *
 * "Run now" also schedules a single `amc_process_queue` event so the
 * queued run starts on the next cron spawn instead of waiting for a tick.
 *
 * Only the standard WP-Cron API is used, so VIP Cron Control and system
 * cron both work. Late or doubled ticks are harmless: per-check locks.
 *
 * Other lanes schedule their own cron events (digest, retention, report
 * schedules) in their own modules; they do not add work to this tick.
 *
 * @since 0.1.0
 */
final class Scheduler implements Registrable {

	/**
	 * Recurring tick hook.
	 */
	public const TICK_HOOK = 'amc_scheduler_tick';

	/**
	 * Single-event hook that drains the "Run now" queue.
	 */
	public const QUEUE_HOOK = 'amc_process_queue';

	/**
	 * Custom cron schedule name.
	 */
	public const INTERVAL = 'amc_every_five_minutes';

	/**
	 * Default time budget per tick, seconds.
	 */
	public const TIME_BUDGET = 25;

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_filter( 'cron_schedules', [ $this, 'add_interval' ] ); // phpcs:ignore WordPress.WP.CronInterval -- 5 minutes is intended; the tick is cheap when nothing is due.
		add_action( self::TICK_HOOK, [ $this, 'on_tick' ] );
		add_action( self::QUEUE_HOOK, [ $this, 'on_queue' ] );

		// Self-heal a missing event, but never on front-end requests.
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			add_action( 'init', [ self::class, 'schedule' ] );
		}
	}

	/**
	 * Add the 5-minute schedule.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Schedules.
	 *
	 * @return array<string, array{interval: int, display: string}>
	 */
	public function add_interval( array $schedules ): array {
		$schedules[ self::INTERVAL ] = [
			'interval' => 5 * MINUTE_IN_SECONDS, // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Intended: the tick is cheap when nothing is due.
			'display'  => __( 'Every five minutes (AMC Copilot)', 'rtcamp-amc-copilot' ),
		];

		return $schedules;
	}

	/**
	 * Schedule the recurring tick (activation, self-heal).
	 */
	public static function schedule(): void {
		if ( false === wp_next_scheduled( self::TICK_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::INTERVAL, self::TICK_HOOK );
		}
	}

	/**
	 * Remove every scheduler event (deactivation, uninstall).
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::TICK_HOOK );
		wp_clear_scheduled_hook( self::QUEUE_HOOK );
	}

	/**
	 * Ask cron to drain the queue as soon as possible.
	 */
	public static function kick(): void {
		if ( false === wp_next_scheduled( self::QUEUE_HOOK ) ) {
			wp_schedule_single_event( time(), self::QUEUE_HOOK );
		}
	}

	/**
	 * Cron callback for the recurring tick.
	 */
	public function on_tick(): void {
		$this->tick();
	}

	/**
	 * Cron callback for the "Run now" queue event.
	 */
	public function on_queue(): void {
		$this->process_queue();
	}

	/**
	 * Recurring tick. See class docblock.
	 *
	 * @param int|null $now Unix time (test seam).
	 *
	 * @return list<string> Check ids that were run (queued runs excluded).
	 */
	public function tick( ?int $now = null ): array {
		if ( KillSwitch::is_active() ) {
			return [];
		}

		Installer::maybe_upgrade();

		$started = microtime( true );
		$budget  = (int) apply_filters( 'amc_scheduler_time_budget', self::TIME_BUDGET );
		$runs    = new RunsRepository();

		$runs->fail_stale( Lock::TTL );

		$this->process_queue( $started, $budget );

		$registry = new Registry();
		$runner   = new Runner( $registry, $runs );
		$due      = self::due_checks( $registry->all(), $runs->last_run_times(), $now ?? time() );
		$ran      = [];

		foreach ( $due as $check_id ) {
			if ( microtime( true ) - $started >= $budget ) {
				break;
			}

			$result = $runner->run( $check_id, 'cron' );
			if ( ! is_wp_error( $result ) ) {
				$ran[] = $check_id;
			}
		}

		return $ran;
	}

	/**
	 * Run queued "Run now" rows, oldest first, within the time budget.
	 *
	 * @param float|null $started microtime(true) of the tick start.
	 * @param int        $budget  Seconds.
	 *
	 * @return int Number of queued runs executed.
	 */
	public function process_queue( ?float $started = null, int $budget = self::TIME_BUDGET ): int {
		if ( KillSwitch::is_active() ) {
			return 0;
		}

		$started = $started ?? microtime( true );
		$runner  = new Runner();
		$count   = 0;

		foreach ( ( new RunsRepository() )->queued() as $row ) {
			if ( microtime( true ) - $started >= $budget ) {
				self::kick();
				break;
			}

			if ( ! is_wp_error( $runner->run_queued( (int) $row['id'] ) ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Which checks are due, oldest due first. Pure function: easy to test.
	 *
	 * @param array<string, \AMC\Checks\Check> $checks     Registered checks keyed by id.
	 * @param array<string, int>               $last_times check_id => unix time of last run.
	 * @param int                              $now        Unix time.
	 *
	 * @return list<string> Check ids.
	 */
	public static function due_checks( array $checks, array $last_times, int $now ): array {
		$due = [];

		foreach ( $checks as $id => $check ) {
			$schedule = Registry::effective_schedule( $check );

			if ( Schedule::EVENT === $schedule || ! Settings::check_enabled( $id ) ) {
				continue;
			}

			// Never run -> due since the epoch, so it sorts first.
			$due_at = isset( $last_times[ $id ] ) ? $last_times[ $id ] + $schedule->seconds() : 0;

			if ( $due_at <= $now ) {
				$due[ $id ] = $due_at;
			}
		}

		// Oldest first; ties by id for a stable order.
		uksort(
			$due,
			static fn ( string $a, string $b ): int => [ $due[ $a ], $a ] <=> [ $due[ $b ], $b ]
		);

		return array_keys( $due );
	}

	/**
	 * Next run time of every scheduled check (Overview "next scheduled runs").
	 *
	 * @param array<string, \AMC\Checks\Check> $checks     Checks keyed by id.
	 * @param array<string, int>               $last_times Last run times.
	 *
	 * @return array<string, int|null> check_id => unix time, null when event-driven or disabled.
	 */
	public static function next_runs( array $checks, array $last_times ): array {
		$next = [];

		foreach ( $checks as $id => $check ) {
			$schedule = Registry::effective_schedule( $check );

			if ( Schedule::EVENT === $schedule || ! Settings::check_enabled( $id ) ) {
				$next[ $id ] = null;
				continue;
			}

			$next[ $id ] = isset( $last_times[ $id ] ) ? $last_times[ $id ] + $schedule->seconds() : time();
		}

		return $next;
	}
}
