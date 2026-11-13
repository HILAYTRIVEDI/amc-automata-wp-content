<?php
/**
 * Tests for the check Registry and value objects.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Checks;

use AMC\Checks\Finding;
use AMC\Checks\Group;
use AMC\Checks\Registry;
use AMC\Checks\Result;
use AMC\Checks\Schedule;
use AMC\Checks\Severity;
use AMC\Checks\Status;
use AMC\Core\Settings;
use AMC\Tests\Support\FakeCheck;
use AMC\Tests\TestCase;

/**
 * Class RegistryTest
 */
final class RegistryTest extends TestCase {

	public function test_wave_zero_registers_updates_core(): void {
		$this->assertTrue( ( new Registry() )->has( 'updates.core' ) );
	}

	public function test_checks_are_added_through_the_filter(): void {
		$this->register_checks( new FakeCheck( 'zz.test', Result::pass( '' ), Schedule::DAILY, Group::USERS ) );
		$registry = new Registry();

		$this->assertSame( 'zz.test', $registry->get( 'zz.test' )->id() );
		$this->assertArrayHasKey( 'zz.test', $registry->in_group( Group::USERS ) );
		$this->assertArrayNotHasKey( 'zz.test', $registry->in_group( Group::UPDATES ) );
	}

	public function test_invalid_and_duplicate_entries_are_skipped(): void {
		$this->setExpectedIncorrectUsage( 'AMC\Checks\Registry::all' );
		add_filter(
			'amc_register_checks',
			static fn ( array $checks ): array => array_merge(
				$checks,
				[ 'not a check', new FakeCheck( 'Bad Id', Result::pass( '' ) ), new FakeCheck( 'updates.core', Result::pass( '' ) ) ]
			)
		);

		$all = ( new Registry() )->all();

		$this->assertArrayNotHasKey( 'Bad Id', $all );
		$this->assertNotInstanceOf( FakeCheck::class, $all['updates.core'], 'First registration wins' );
	}

	public function test_effective_schedule_uses_settings_override(): void {
		$check = new FakeCheck( 'zz.sched', Result::pass( '' ), Schedule::HOURLY );
		$this->assertSame( Schedule::HOURLY, Registry::effective_schedule( $check ) );

		Settings::update( [ 'checks' => [ 'zz.sched' => [ 'interval' => 'weekly' ] ] ] );
		$this->assertSame( Schedule::WEEKLY, Registry::effective_schedule( $check ) );
	}

	public function test_value_objects(): void {
		$result = Result::fail( 'Bad', [ 'k' => 'v' ], [ new Finding( 'key', Severity::CRITICAL, 'T', [ 'd' => 1 ] ) ] );

		$this->assertSame( Status::FAIL, $result->status );
		$this->assertSame( 'critical', $result->to_array()['findings'][0]['severity'] );
		$this->assertTrue( Severity::CRITICAL->at_least( Severity::HIGH ) );
		$this->assertFalse( Severity::LOW->at_least( Severity::MEDIUM ) );
		$this->assertSame( DAY_IN_SECONDS, Schedule::DAILY->seconds() );
		$this->assertCount( 11, Group::cases() );
	}

	public function test_finding_rejects_bad_keys_and_result_rejects_non_findings(): void {
		$this->expectException( \InvalidArgumentException::class );
		new Finding( str_repeat( 'x', 192 ), Severity::LOW, 'T' );
	}

	public function test_result_rejects_non_findings(): void {
		$this->expectException( \InvalidArgumentException::class );
		new Result( Status::PASS, '', [], [ 'nope' ] );
	}
}
