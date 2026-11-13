<?php
/**
 * Tests for the Cron module and the example cron job.
 *
 * MainTest already covers deactivate() unscheduling the event, so that path
 * is not repeated here.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Tests;

use AMC_Automata_Features\Features\Modules\Cron;
use AMC_Automata_Features\Features\Modules\Cron\ExampleCronJob;
use ReflectionMethod;
use rtCamp\WPPrimitives\Contracts\Interfaces\Registrable;

/**
 * Class CronTest
 */
final class CronTest extends TestCase {

	/**
	 * {@inheritDoc}
	 *
	 * Start from a clean schedule: the booted plugin schedules the event on init,
	 * so clear it before each test asserts on a pristine state.
	 */
	public function set_up(): void {
		parent::set_up();

		wp_clear_scheduled_hook( ExampleCronJob::HOOK );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Never let a scheduled event leak into another test.
	 */
	public function tear_down(): void {
		wp_clear_scheduled_hook( ExampleCronJob::HOOK );

		parent::tear_down();
	}

	/**
	 * The HOOK constant carries the documented event name.
	 */
	public function test_hook_constant_value(): void {
		$this->assertSame( 'amc_automata_features_features_daily_cleanup', ExampleCronJob::HOOK );
	}

	/**
	 * The job is a framework Registrable.
	 */
	public function test_implements_registrable(): void {
		$this->assertInstanceOf( Registrable::class, new ExampleCronJob() );
	}

	/**
	 * The job is one of the module's managed classes.
	 */
	public function test_job_is_in_module_get_classes(): void {
		$method = new ReflectionMethod( Cron::class, 'get_classes' );
		$method->setAccessible( true );

		$this->assertContains( ExampleCronJob::class, $method->invoke( new Cron() ) );
	}

	/**
	 * register_hooks() wires both the scheduling guard and the cron callback.
	 */
	public function test_register_hooks_wires_schedule_and_callback(): void {
		$job = new ExampleCronJob();
		$job->register_hooks();

		$this->assertNotFalse( has_action( 'init', [ $job, 'maybe_schedule' ] ) );
		$this->assertNotFalse( has_action( ExampleCronJob::HOOK, [ $job, 'run' ] ) );
	}

	/**
	 * maybe_schedule() schedules the recurring event when none exists.
	 */
	public function test_maybe_schedule_schedules_the_event(): void {
		$this->assertFalse( wp_next_scheduled( ExampleCronJob::HOOK ) );

		( new ExampleCronJob() )->maybe_schedule();

		$this->assertNotFalse( wp_next_scheduled( ExampleCronJob::HOOK ) );
		$this->assertSame(
			ExampleCronJob::RECURRENCE,
			wp_get_schedule( ExampleCronJob::HOOK )
		);
	}

	/**
	 * maybe_schedule() is idempotent: a second call does not reschedule.
	 */
	public function test_maybe_schedule_is_idempotent(): void {
		$job = new ExampleCronJob();

		$job->maybe_schedule();
		$first = wp_next_scheduled( ExampleCronJob::HOOK );

		$job->maybe_schedule();
		$second = wp_next_scheduled( ExampleCronJob::HOOK );

		$this->assertSame( $first, $second );
	}

	/**
	 * run() deletes the daily cache transient (its documented side effect).
	 */
	public function test_run_deletes_daily_cache_transient(): void {
		set_transient( 'amc_automata_features_features_daily_cache', 'cached-value', HOUR_IN_SECONDS );
		$this->assertSame( 'cached-value', get_transient( 'amc_automata_features_features_daily_cache' ) );

		( new ExampleCronJob() )->run();

		$this->assertFalse( get_transient( 'amc_automata_features_features_daily_cache' ) );
	}

	/**
	 * unschedule() clears a scheduled event.
	 */
	public function test_unschedule_clears_the_event(): void {
		wp_schedule_event( time(), ExampleCronJob::RECURRENCE, ExampleCronJob::HOOK );
		$this->assertNotFalse( wp_next_scheduled( ExampleCronJob::HOOK ) );

		ExampleCronJob::unschedule();

		$this->assertFalse( wp_next_scheduled( ExampleCronJob::HOOK ) );
	}
}
