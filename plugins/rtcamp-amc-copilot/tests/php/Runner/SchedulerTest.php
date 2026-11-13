<?php
/**
 * Tests for the WP-Cron scheduler.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Runner;

use AMC\Checks\Result;
use AMC\Checks\Schedule;
use AMC\Core\Settings;
use AMC\Runner\Runner;
use AMC\Runner\Scheduler;
use AMC\Storage\RunsRepository;
use AMC\Tests\Support\FakeCheck;
use AMC\Tests\TestCase;

/**
 * Class SchedulerTest
 */
final class SchedulerTest extends TestCase {

	public function test_due_checks_catch_up_once_oldest_first(): void {
		$now    = 1_800_000_000;
		$checks = [
			'a.hourly' => new FakeCheck( 'a.hourly', Result::pass( '' ), Schedule::HOURLY ),
			'b.daily'  => new FakeCheck( 'b.daily', Result::pass( '' ), Schedule::DAILY ),
			'c.never'  => new FakeCheck( 'c.never', Result::pass( '' ), Schedule::WEEKLY ),
			'd.fresh'  => new FakeCheck( 'd.fresh', Result::pass( '' ), Schedule::HOURLY ),
			'e.event'  => new FakeCheck( 'e.event', Result::pass( '' ), Schedule::EVENT ),
		];
		$last   = [
			'a.hourly' => $now - 5 * HOUR_IN_SECONDS,   // Missed 4 runs: due once.
			'b.daily'  => $now - 2 * DAY_IN_SECONDS,    // Due earlier than a.hourly.
			'd.fresh'  => $now - 10 * MINUTE_IN_SECONDS, // Not due.
		];

		$due = Scheduler::due_checks( $checks, $last, $now );

		$this->assertSame( [ 'c.never', 'b.daily', 'a.hourly' ], $due );
	}

	public function test_disabled_checks_and_interval_overrides_are_respected(): void {
		$now    = 1_800_000_000;
		$checks = [
			'a.one' => new FakeCheck( 'a.one', Result::pass( '' ), Schedule::HOURLY ),
			'b.two' => new FakeCheck( 'b.two', Result::pass( '' ), Schedule::HOURLY ),
		];
		Settings::update(
			[
				'checks' => [
					'a.one' => [ 'enabled' => false ],
					'b.two' => [ 'interval' => 'daily' ],
				],
			]
		);

		$due = Scheduler::due_checks( $checks, [ 'b.two' => $now - 2 * HOUR_IN_SECONDS ], $now );

		$this->assertSame( [], $due );
	}

	public function test_tick_runs_queue_then_due_checks_and_skips_fresh_ones(): void {
		$due    = new FakeCheck( 'test.due', Result::pass( '' ) );
		$queued = new FakeCheck( 'test.queued', Result::pass( '' ), Schedule::EVENT );
		$this->register_checks( $due, $queued );
		$run_id = ( new Runner() )->enqueue( 'test.queued' );

		$ran = ( new Scheduler() )->tick();

		$this->assertContains( 'test.due', $ran );
		$this->assertNotContains( 'test.queued', $ran, 'Event-driven checks are never scheduled' );
		$this->assertSame( 'pass', ( new RunsRepository() )->find( $run_id )['status'] );

		// Second tick right away: nothing is due.
		$this->assertSame( [], ( new Scheduler() )->tick() );
	}

	public function test_tick_does_nothing_when_killed(): void {
		$this->register_checks( new FakeCheck( 'test.due', Result::pass( '' ) ) );
		Settings::update( [ 'kill_switch' => true ] );

		$this->assertSame( [], ( new Scheduler() )->tick() );
		$this->assertSame( 0, ( new RunsRepository() )->query()['total'] );
	}

	public function test_schedule_and_unschedule(): void {
		Scheduler::unschedule();
		$this->assertFalse( wp_next_scheduled( Scheduler::TICK_HOOK ) );

		Scheduler::schedule();
		$this->assertNotFalse( wp_next_scheduled( Scheduler::TICK_HOOK ) );
		$this->assertArrayHasKey( Scheduler::INTERVAL, wp_get_schedules() );

		Scheduler::unschedule();
		$this->assertFalse( wp_next_scheduled( Scheduler::TICK_HOOK ) );
	}
}
