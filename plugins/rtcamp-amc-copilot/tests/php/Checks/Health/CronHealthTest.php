<?php
/**
 * Tests for the health.cron check.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Checks\Health;

use AMC\Checks\Context;
use AMC\Checks\Group;
use AMC\Checks\Health\CronHealth;
use AMC\Checks\Schedule;
use AMC\Checks\Severity;
use AMC\Checks\Status;
use AMC\Tests\TestCase;

/**
 * Class CronHealthTest
 *
 * An event is overdue when its scheduled time is more than one hour in the
 * past (strictly before now - 3600).
 */
final class CronHealthTest extends TestCase {

	/**
	 * Pinned "now": 2026-11-14 10:00:00 UTC.
	 *
	 * @var int
	 */
	private const NOW = 1794650400;

	/**
	 * Context pinned to the same clock as self::NOW.
	 */
	private function ctx(): Context {
		return Context::for_tests( null, '2026-11-14 10:00:00' );
	}

	/**
	 * Build the check with a canned cron array.
	 *
	 * @param array<int|string, mixed> $cron Cron array (timestamp => hook => events).
	 */
	private function check_with( array $cron ): CronHealth {
		return new CronHealth( static fn (): array => $cron );
	}

	public function test_metadata(): void {
		$check = new CronHealth();

		$this->assertSame( 'health.cron', $check->id() );
		$this->assertSame( Group::HEALTH, $check->group() );
		$this->assertSame( Schedule::HOURLY, $check->schedule() );
		$this->assertNotSame( '', $check->label() );
	}

	public function test_fresh_events_pass_without_findings(): void {
		$result = $this->check_with(
			[
				self::NOW - 1800 => [
					'wp_update_plugins' => [ [] ],
				],
				self::NOW + 3600 => [
					'wp_scheduled_delete' => [ [] ],
				],
			]
		)->run( $this->ctx() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( [], $result->findings );
		$this->assertSame( [], $result->data['overdue'] );
		$this->assertSame( 2, $result->data['total_events'] );
		$this->assertStringContainsString( 'No overdue cron events', $result->summary );
	}

	public function test_an_event_exactly_one_hour_old_is_not_overdue(): void {
		$result = $this->check_with(
			[
				self::NOW - 3600 => [
					'wp_update_plugins' => [ [] ],
				],
			]
		)->run( $this->ctx() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( [], $result->data['overdue'] );
		$this->assertSame( 1, $result->data['total_events'] );
	}

	public function test_overdue_events_warn_with_one_finding_each(): void {
		$result = $this->check_with(
			[
				self::NOW - 7200  => [
					'wp_scheduled_delete' => [ [] ],
				],
				self::NOW - 86400 => [
					'wp_update_themes' => [ [] ],
					'wp_version_check' => [ [] ],
				],
			]
		)->run( $this->ctx() );

		$this->assertSame( Status::WARN, $result->status );
		$this->assertSame( 3, $result->data['total_events'] );
		$this->assertCount( 3, $result->data['overdue'] );

		$this->assertCount( 3, $result->findings );
		$this->assertSame( 'cron:wp_scheduled_delete', $result->findings[0]->dedupe_key );
		$this->assertSame( 'cron:wp_update_themes', $result->findings[1]->dedupe_key );
		$this->assertSame( Severity::MEDIUM, $result->findings[0]->severity );

		$this->assertSame( 'wp_scheduled_delete', $result->data['overdue'][0]['hook'] );
		$this->assertSame( 7200, $result->data['overdue'][0]['overdue_seconds'] );
		$this->assertSame( gmdate( 'c', self::NOW - 7200 ), $result->data['overdue'][0]['scheduled'] );
		$this->assertStringContainsString( 'Overdue cron event: wp_scheduled_delete', $result->findings[0]->title );
		$this->assertStringContainsString( 'overdue cron event', $result->summary );
	}

	public function test_malformed_rows_are_skipped(): void {
		$result = $this->check_with(
			[
				'garbage'        => 'not-an-array',
				self::NOW - 1800 => 'also-not-an-array',
				self::NOW - 900  => [
					'wp_update_plugins' => [ [] ],
				],
			]
		)->run( $this->ctx() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( 1, $result->data['total_events'] );
	}

	public function test_result_is_json_serialisable(): void {
		$result = $this->check_with(
			[
				self::NOW - 7200 => [
					'wp_scheduled_delete' => [ [] ],
				],
			]
		)->run( $this->ctx() );

		$this->assertIsString( wp_json_encode( $result->to_array() ) );
	}
}
