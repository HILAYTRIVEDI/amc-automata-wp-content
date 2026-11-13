<?php
/**
 * Example daily WP-Cron job.
 *
 * Demonstrates implementing Registrable directly when no specialised
 * abstract (post type, taxonomy, REST controller, …) applies and you
 * just want to wire one or more WordPress hooks from a single class.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Modules\Cron;

use rtCamp\WPPrimitives\Contracts\Interfaces\Registrable;

/**
 * Class - ExampleCronJob
 *
 * Schedules a recurring WP-Cron event and runs cleanup work on each tick.
 *
 * Lifecycle:
 *   1. register_hooks() wires a scheduling guard on `init` and the
 *      callback for the cron event itself.
 *   2. On the first request after activation, maybe_schedule() registers
 *      the recurring event with WP-Cron.
 *   3. WP-Cron fires {@see HOOK} on the configured schedule and run()
 *      executes the actual work.
 *
 * For plugin deactivation, call {@see self::unschedule()} from the
 * deactivation hook to clear the scheduled event.
 */
final class ExampleCronJob implements Registrable {

	/**
	 * Hook name fired by WP-Cron on each tick.
	 */
	public const HOOK = 'amc_automata_features_features_daily_cleanup';

	/**
	 * Recurrence — must be a registered WP-Cron schedule
	 * (e.g. 'hourly', 'twicedaily', 'daily', 'weekly').
	 */
	public const RECURRENCE = 'daily';

	/**
	 * {@inheritDoc}
	 *
	 * Wires two hooks:
	 *   - `init` to ensure the event is scheduled (idempotent).
	 *   - The cron hook itself for when WP-Cron actually fires.
	 */
	public function register_hooks(): void {
		add_action( 'init', [ $this, 'maybe_schedule' ] );
		add_action( self::HOOK, [ $this, 'run' ] );
	}

	/**
	 * Schedule the recurring event if it isn't already.
	 *
	 * Safe to call on every request: wp_next_scheduled() short-circuits
	 * when the event already exists.
	 */
	public function maybe_schedule(): void {
		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		wp_schedule_event( time(), self::RECURRENCE, self::HOOK );
	}

	/**
	 * Cron job body. Runs once per recurrence.
	 *
	 * Replace with the actual cleanup / sync / reporting work for the project.
	 */
	public function run(): void {
		delete_transient( 'amc_automata_features_features_daily_cache' );
	}

	/**
	 * Unschedule the event entirely.
	 *
	 * Call from the plugin's deactivation hook so an uninstall doesn't
	 * leave a phantom cron entry pointing at a now-missing callback.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}
}
