<?php
/**
 * Tests for the report schedules (WP-Cron), including daylight saving changes.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Reports;

use AMC\Core\Settings;
use AMC\Reports\Generator;
use AMC\Reports\ReportPostType;
use AMC\Reports\ReportSchedules;
use AMC\Runner\Lock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Class ReportSchedulesTest
 *
 * The site is on Europe/London (BST from 29 Mar to 25 Oct 2026), so a 06:00
 * site-time event is 05:00 UTC in summer and 06:00 UTC in winter.
 */
final class ReportSchedulesTest extends ReportsTestCase {

	/**
	 * The test clock (UTC).
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $now;

	/**
	 * {@inheritDoc}
	 */
	public function set_up(): void {
		parent::set_up();

		update_option( 'timezone_string', 'Europe/London' );

		foreach ( ReportSchedules::HOOKS as $hook ) {
			wp_unschedule_hook( $hook );
		}

		$this->set_clock( '2026-03-10 12:00:00' );
	}

	/**
	 * Set the test clock.
	 *
	 * @param string $utc UTC time.
	 */
	private function set_clock( string $utc ): void {
		$this->now = new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Schedules wired to the test clock and stub facts.
	 */
	private function schedules(): ReportSchedules {
		$facts   = new StubSiteFacts();
		$clock   = fn (): DateTimeImmutable => $this->now;
		$builder = new \AMC\Reports\SnapshotBuilder( $facts, null, null, null, null, null, null, new DateTimeZone( 'Europe/London' ), $clock );

		return new ReportSchedules( new Generator( $builder, $facts, $clock ) );
	}

	/**
	 * Scheduled UTC time of a report hook, 'Y-m-d H:i:s'.
	 *
	 * @param string $type month | quarter | year.
	 */
	private function scheduled( string $type ): string {
		$events = _get_cron_array();
		$hook   = ReportSchedules::HOOKS[ $type ];

		foreach ( $events as $timestamp => $by_hook ) {
			if ( isset( $by_hook[ $hook ] ) ) {
				return gmdate( 'Y-m-d H:i:s', (int) $timestamp );
			}
		}

		return 'not scheduled';
	}

	/**
	 * Count scheduled events of a hook.
	 *
	 * @param string $hook Hook.
	 */
	private function event_count( string $hook ): int {
		$count = 0;

		foreach ( _get_cron_array() as $by_hook ) {
			$count += isset( $by_hook[ $hook ] ) ? count( $by_hook[ $hook ] ) : 0;
		}

		return $count;
	}

	/**
	 * Report posts, any status.
	 *
	 * @return list<int>
	 */
	private function reports(): array {
		return array_map(
			'intval',
			get_posts(
				[
					'post_type'   => ReportPostType::SLUG,
					'post_status' => 'any',
					'numberposts' => 50,
					'fields'      => 'ids',
				]
			)
		);
	}

	public function test_events_are_scheduled_at_the_site_time_runs(): void {
		$this->schedules()->schedule_all();

		// Before the clocks go forward: 1 April is BST, so 06:00 local is 05:00 UTC.
		$this->assertSame( '2026-04-01 05:00:00', $this->scheduled( 'month' ), 'monthly: 1st, 06:00' );
		$this->assertSame( '2026-04-01 05:15:00', $this->scheduled( 'quarter' ), 'quarterly: first day of the quarter, 06:15' );
		$this->assertSame( '2027-01-01 06:30:00', $this->scheduled( 'year' ), 'annual: calendar year, 06:30, GMT' );
	}

	public function test_a_run_in_winter_time_is_one_hour_later_in_utc(): void {
		$this->set_clock( '2026-10-10 12:00:00' );
		$this->schedules()->schedule_all();

		// 25 Oct: clocks went back. 1 Nov 06:00 London is 06:00 UTC.
		$this->assertSame( '2026-11-01 06:00:00', $this->scheduled( 'month' ) );
		$this->assertSame( '2026-10-01 05:15:00', gmdate( 'Y-m-d H:i:s', $this->schedules()->next_run( 'quarter', new DateTimeImmutable( '2026-09-20', new DateTimeZone( 'UTC' ) ) )->getTimestamp() ), 'before the change: still BST' );
		$this->assertSame( '2027-01-01 06:15:00', $this->scheduled( 'quarter' ) );
	}

	public function test_the_run_across_the_spring_change_day_keeps_its_local_time(): void {
		Settings::update( [ 'contract_start' => '2024-03-29' ] ); // The anniversary IS the day the clocks go forward.

		$this->set_clock( '2026-03-10 12:00:00' );
		$this->schedules()->schedule_all();

		// 29 March 2026 06:30 BST = 05:30 UTC.
		$this->assertSame( '2026-03-29 05:30:00', $this->scheduled( 'year' ) );
	}

	public function test_schedule_all_is_idempotent_and_replaces_a_stale_event(): void {
		$schedules = $this->schedules();

		$schedules->schedule_all();
		$schedules->schedule_all();

		foreach ( ReportSchedules::HOOKS as $hook ) {
			$this->assertSame( 1, $this->event_count( $hook ), $hook );
		}

		// A stale event (wrong time) is replaced, not added to.
		wp_unschedule_hook( ReportSchedules::HOOKS['month'] );
		wp_schedule_single_event( strtotime( '2026-03-12 00:00:00 UTC' ), ReportSchedules::HOOKS['month'], [ 123 ] );
		$schedules->schedule_all();

		$this->assertSame( 1, $this->event_count( ReportSchedules::HOOKS['month'] ) );
		$this->assertSame( '2026-04-01 05:00:00', $this->scheduled( 'month' ) );
	}

	public function test_the_monthly_run_creates_one_draft_for_the_previous_month_and_schedules_the_next(): void {
		$this->set_clock( '2026-11-01 06:00:05' );
		$schedules = $this->schedules();

		$id = $schedules->run( 'month', (int) strtotime( '2026-11-01 06:00:00 UTC' ) );

		$this->assertIsInt( $id );
		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertSame( '2026-10-01', get_post_meta( $id, '_amc_period_start', true ) );
		$this->assertSame( '2026-10-31', get_post_meta( $id, '_amc_period_end', true ) );
		$this->assertSame( 'month', get_post_meta( $id, '_amc_period_type', true ) );
		$this->assertSame( ReportPostType::BUILD_READY, get_post_meta( $id, '_amc_build_status', true ) );
		$this->assertSame( '2026-12-01 06:00:00', $this->scheduled( 'month' ), 'next: 1 Dec, 06:00 GMT' );
	}

	public function test_a_doubled_or_late_run_never_makes_a_second_draft(): void {
		$this->set_clock( '2026-11-01 06:00:05' );
		$schedules = $this->schedules();

		$first = $schedules->run( 'month', (int) strtotime( '2026-11-01 06:00:00 UTC' ) );

		$this->set_clock( '2026-11-01 06:00:40' );
		$second = $schedules->run( 'month', (int) strtotime( '2026-11-01 06:00:00 UTC' ) );

		$this->set_clock( '2026-11-01 17:30:00' );
		$third = $schedules->run( 'month', 0 );

		$this->assertIsInt( $first );
		$this->assertNull( $second, 'the period already has its report' );
		$this->assertNull( $third );
		$this->assertSame( [ $first ], $this->reports() );
		$this->assertSame( 1, $this->event_count( ReportSchedules::HOOKS['month'] ), 'one next event, not three' );
	}

	public function test_a_stray_run_on_an_ordinary_day_does_nothing(): void {
		$this->set_clock( '2026-11-15 10:00:00' );
		$schedules = $this->schedules();

		$this->assertNull( $schedules->run( 'month', 0 ), 'wp cron event run on the 15th' );
		$this->assertSame( [], $this->reports() );
		$this->assertSame( '2026-12-01 06:00:00', $this->scheduled( 'month' ) );
	}

	public function test_an_event_delivered_days_late_still_generates_once(): void {
		$this->set_clock( '2026-11-04 09:00:00' );
		$schedules = $this->schedules();

		$id = $schedules->run( 'month', (int) strtotime( '2026-11-01 06:00:00 UTC' ) );

		$this->assertIsInt( $id, 'WP-Cron was down on the 1st' );
		$this->assertSame( '2026-10-01', get_post_meta( $id, '_amc_period_start', true ) );
		$this->assertNull( $schedules->run( 'month', (int) strtotime( '2026-11-01 06:00:00 UTC' ) ) );
		$this->assertSame( [ $id ], $this->reports() );
	}

	public function test_the_quarterly_run_covers_the_previous_quarter(): void {
		$this->set_clock( '2026-10-01 05:15:30' ); // 06:15 BST on the first day of Q4.
		$schedules = $this->schedules();

		$id = $schedules->run( 'quarter', (int) strtotime( '2026-10-01 05:15:00 UTC' ) );

		$this->assertIsInt( $id );
		$this->assertSame( 'quarter', get_post_meta( $id, '_amc_period_type', true ) );
		$this->assertSame( '2026-07-01', get_post_meta( $id, '_amc_period_start', true ) );
		$this->assertSame( '2026-09-30', get_post_meta( $id, '_amc_period_end', true ) );
		$this->assertSame( 'AMC-NWT-2026-Q3', get_post_meta( $id, '_amc_report_id', true ) );
		$this->assertSame( '2027-01-01 06:15:00', $this->scheduled( 'quarter' ) );
	}

	public function test_the_annual_run_follows_the_contract_anniversary(): void {
		Settings::update( [ 'contract_start' => '2025-11-14' ] );

		$this->set_clock( '2026-11-14 06:30:10' );
		$schedules = $this->schedules();

		$id = $schedules->run( 'year', (int) strtotime( '2026-11-14 06:30:00 UTC' ) );

		$this->assertIsInt( $id );
		$this->assertSame( '2025-11-14', get_post_meta( $id, '_amc_period_start', true ) );
		$this->assertSame( '2026-11-13', get_post_meta( $id, '_amc_period_end', true ) );
		$this->assertSame( 'AMC-NWT-2025-26', get_post_meta( $id, '_amc_report_id', true ) );
		$this->assertSame( '2027-11-14 06:30:00', $this->scheduled( 'year' ) );
	}

	public function test_the_kill_switch_stops_generation_but_the_schedule_goes_on(): void {
		Settings::update( [ 'kill_switch' => true ] );

		$this->set_clock( '2026-11-01 06:00:05' );
		$schedules = $this->schedules();

		$this->assertNull( $schedules->run( 'month', (int) strtotime( '2026-11-01 06:00:00 UTC' ) ) );
		$this->assertSame( [], $this->reports() );
		$this->assertSame( '2026-12-01 06:00:00', $this->scheduled( 'month' ) );
	}

	public function test_a_held_lock_skips_the_run(): void {
		$lock = Lock::named( 'report-schedule:month', 300 );
		$this->assertTrue( $lock->acquire() );

		$this->set_clock( '2026-11-01 06:00:05' );
		$schedules = $this->schedules();

		$this->assertNull( $schedules->run( 'month', (int) strtotime( '2026-11-01 06:00:00 UTC' ) ) );
		$this->assertSame( [], $this->reports() );

		$lock->release();
	}

	public function test_changing_the_contract_start_reschedules(): void {
		$schedules = $this->schedules();
		$schedules->schedule_all();

		$this->assertSame( '2026-04-01 05:15:00', $this->scheduled( 'quarter' ) );

		Settings::update( [ 'contract_start' => '2025-05-20' ] );
		$schedules->on_settings_updated( [ 'contract_start' => '' ], [ 'contract_start' => '2025-05-20' ] );

		$this->assertSame( '2026-05-20 05:15:00', $this->scheduled( 'quarter' ), 'quarters now start on the 20th of Feb, May, Aug, Nov' );
		$this->assertSame( '2026-05-20 05:30:00', $this->scheduled( 'year' ), 'the anniversary is 20 May' );
		$this->assertSame( 1, $this->event_count( ReportSchedules::HOOKS['quarter'] ) );

		// An unrelated settings change leaves the schedule alone.
		$before = $this->scheduled( 'quarter' );
		$schedules->on_settings_updated( [ 'contract_start' => '2025-05-20' ], [ 'contract_start' => '2025-05-20' ] );
		$this->assertSame( $before, $this->scheduled( 'quarter' ) );
	}

	public function test_unschedule_all_clears_every_report_event_and_queued_builds(): void {
		$schedules = $this->schedules();
		$schedules->schedule_all();
		wp_schedule_single_event( time(), Generator::BUILD_HOOK, [ 5 ] );

		$schedules->unschedule_all();

		foreach ( array_merge( array_values( ReportSchedules::HOOKS ), [ Generator::BUILD_HOOK ] ) as $hook ) {
			$this->assertSame( 0, $this->event_count( $hook ), $hook );
		}
	}

	public function test_activation_schedules_and_deactivation_clears_through_the_lane_hooks(): void {
		do_action( 'amc_activate' );

		foreach ( ReportSchedules::HOOKS as $hook ) {
			$this->assertSame( 1, $this->event_count( $hook ), $hook );
		}

		do_action( 'amc_deactivate' );

		foreach ( ReportSchedules::HOOKS as $hook ) {
			$this->assertSame( 0, $this->event_count( $hook ), $hook );
		}
	}

	public function test_the_cron_hooks_are_wired(): void {
		foreach ( ReportSchedules::HOOKS as $hook ) {
			$this->assertTrue( has_action( $hook ), $hook );
		}

		$this->assertTrue( has_action( Generator::BUILD_HOOK ) );
	}
}
