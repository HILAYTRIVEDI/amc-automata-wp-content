<?php
/**
 * WP-Cron schedules for monthly, quarterly and annual reports.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports;

use AMC\Core\KillSwitch;
use AMC\Core\Settings;
use AMC\Helpers\Util;
use AMC\Runner\Lock;
use DateTimeImmutable;
use rtCamp\WPPrimitives\Contracts\Interfaces\Registrable;

/**
 * Class ReportSchedules
 *
 * Three cron events (names reserved in docs/CONTRACTS.md):
 *
 *   amc_report_monthly    1st of each month, 06:00 site time, previous month
 *   amc_report_quarterly  1st day of each contract quarter, 06:15, previous quarter
 *   amc_report_annual     contract anniversary, 06:30, previous 12 months
 *
 * Quarters and years follow `contract_start` in Settings, or the calendar.
 *
 * Each is a SINGLE event scheduled for `Periods::next_run()` (site time, so it
 * stays at 06:00 across daylight saving changes). When it fires it generates
 * the report and schedules the next one, so there is never a stale recurring
 * interval to drift. The event carries its scheduled timestamp as its argument,
 * so a run that WP-Cron delivers days late still generates (catch-up once),
 * while a manual `wp cron event run` on an ordinary day does nothing.
 *
 * Idempotent: the Generator refuses a period that already has a report, and a
 * named lock stops two overlapping runs, so there are never two drafts for the
 * same period.
 *
 * Events are scheduled on `amc_activate` and when an admin, cron or CLI request
 * finds one missing; they are cleared on `amc_deactivate`. Changing the
 * contract start reschedules them. The kill switch stops generation.
 *
 * @since 0.1.0
 */
final class ReportSchedules implements Registrable {

	/**
	 * Period type => cron hook.
	 */
	public const HOOKS = [
		Period::TYPE_MONTH   => 'amc_report_monthly',
		Period::TYPE_QUARTER => 'amc_report_quarterly',
		Period::TYPE_YEAR    => 'amc_report_annual',
	];

	/**
	 * Constructor.
	 *
	 * @param Generator|null $generator Generator.
	 */
	public function __construct( private ?Generator $generator = null ) {
		$this->generator ??= new Generator();
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		foreach ( self::HOOKS as $type => $hook ) {
			add_action(
				$hook,
				function ( $scheduled_for = 0 ) use ( $type ): void {
					$this->run( $type, (int) $scheduled_for );
				}
			);
		}

		add_action( 'amc_activate', [ $this, 'schedule_all' ] );
		add_action( 'amc_deactivate', [ $this, 'unschedule_all' ] );
		add_action( 'update_option_' . Settings::OPTION, [ $this, 'on_settings_updated' ], 10, 2 );

		// Self-heal a missing event, but never on a front-end request.
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			add_action( 'init', [ $this, 'schedule_all' ] );
		}
	}

	/**
	 * Schedule every report event that is missing or stale.
	 */
	public function schedule_all(): void {
		foreach ( array_keys( self::HOOKS ) as $type ) {
			$this->schedule( $type );
		}
	}

	/**
	 * Schedule one event for its next run.
	 *
	 * @param string                 $type  month | quarter | year.
	 * @param DateTimeImmutable|null $after Moment to count from (default: now).
	 *
	 * @return int Scheduled timestamp.
	 */
	public function schedule( string $type, ?DateTimeImmutable $after = null ): int {
		$hook = self::HOOKS[ $type ];
		$next = $this->next_run( $type, $after );
		$ts   = $next->getTimestamp();

		if ( false === wp_next_scheduled( $hook, [ $ts ] ) ) {
			wp_unschedule_hook( $hook );
			wp_schedule_single_event( $ts, $hook, [ $ts ] );
		}

		return $ts;
	}

	/**
	 * Remove every report event (deactivation, uninstall).
	 */
	public function unschedule_all(): void {
		foreach ( self::HOOKS as $hook ) {
			wp_unschedule_hook( $hook );
		}

		wp_unschedule_hook( Generator::BUILD_HOOK );
	}

	/**
	 * The next run instant of a report type, in site time.
	 *
	 * @param string                 $type  month | quarter | year.
	 * @param DateTimeImmutable|null $after Moment to count from (default: now).
	 */
	public function next_run( string $type, ?DateTimeImmutable $after = null ): DateTimeImmutable {
		return Periods::next_run( $type, $after ?? $this->generator->now(), $this->generator->contract_start(), $this->generator->tz() );
	}

	/**
	 * Reschedule when the contract start changed.
	 *
	 * @param mixed $old Old settings.
	 * @param mixed $updated New settings.
	 */
	public function on_settings_updated( mixed $old, mixed $updated ): void {
		$before = is_array( $old ) ? (string) ( $old['contract_start'] ?? '' ) : '';
		$after  = is_array( $updated ) ? (string) ( $updated['contract_start'] ?? '' ) : '';

		if ( $before !== $after ) {
			$this->schedule_all();
		}
	}

	/**
	 * Cron callback: generate this type's report when it is due, then schedule the next.
	 *
	 * @param string $type          month | quarter | year.
	 * @param int    $scheduled_for Timestamp the event was scheduled for (0 = unknown).
	 *
	 * @return int|\WP_Error|null The new report ID, the error, or null when nothing was generated.
	 */
	public function run( string $type, int $scheduled_for = 0 ): int|\WP_Error|null {
		if ( ! isset( self::HOOKS[ $type ] ) ) {
			return null;
		}

		$lock = Lock::named( 'report-schedule:' . $type, 300 );

		if ( ! $lock->acquire() ) {
			return null;
		}

		try {
			$result = null;
			$now    = $this->generator->now();

			if ( ! KillSwitch::is_active() && $this->should_generate( $type, $now, $scheduled_for ) ) {
				$period = $this->generator->resolve_period( $type );

				if ( $period instanceof Period ) {
					$generated = $this->generator->generate( $period, 0, 'cron' );

					// A report that already exists is the idempotent outcome, not a failure.
					if ( $generated instanceof \WP_Error && 'amc_report_exists' !== $generated->get_error_code() ) {
						Util::logger()->error(
							'Scheduled report failed',
							[
								'type'  => $type,
								'error' => $generated->get_error_message(),
							]
						);
					}

					$result = $generated instanceof \WP_Error && 'amc_report_exists' === $generated->get_error_code() ? null : $generated;
				}
			}

			$this->schedule( $type, $now->modify( '+1 second' ) );

			return $result;
		} finally {
			$lock->release();
		}
	}

	/**
	 * Whether a fired event should generate: it is the run day, or WP-Cron
	 * delivered it on a later day than it was scheduled for.
	 *
	 * @param string            $type          month | quarter | year.
	 * @param DateTimeImmutable $now           Now.
	 * @param int               $scheduled_for Timestamp the event was scheduled for.
	 */
	private function should_generate( string $type, DateTimeImmutable $now, int $scheduled_for ): bool {
		$tz = $this->generator->tz();

		if ( Periods::is_due( $type, $now, $this->generator->contract_start(), $tz ) ) {
			return true;
		}

		if ( $scheduled_for <= 0 ) {
			return false;
		}

		$start_of_today = $now->setTimezone( $tz )->setTime( 0, 0, 0 );

		return $scheduled_for < $start_of_today->getTimestamp();
	}
}
