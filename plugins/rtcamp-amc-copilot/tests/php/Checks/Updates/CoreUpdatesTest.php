<?php
/**
 * Tests for the updates.core check.
 *
 * ==== EXEMPLAR CHECK TEST: copy this file for every new check. ====
 *
 * Pattern:
 *   - Build the check with fake sources (fixtures), never the real site.
 *   - Call run( Context::for_tests() ) and assert status, data and findings.
 *   - One test per status branch, plus one for the metadata.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Checks\Updates;

use AMC\Checks\Context;
use AMC\Checks\Group;
use AMC\Checks\Schedule;
use AMC\Checks\Severity;
use AMC\Checks\Status;
use AMC\Checks\Updates\CoreUpdates;
use AMC\Tests\TestCase;

/**
 * Class CoreUpdatesTest
 */
final class CoreUpdatesTest extends TestCase {

	/**
	 * Build the check with a fixture as the update_core transient and 6.9.1 installed.
	 *
	 * @param string $fixture Fixture name in fixtures/updates-core/.
	 */
	private function check_with( string $fixture ): CoreUpdates {
		$transient = $this->fixture( 'updates-core/' . $fixture );

		return new CoreUpdates(
			static fn () => $transient,
			static fn (): string => '6.9.1'
		);
	}

	public function test_metadata(): void {
		$check = new CoreUpdates();

		$this->assertSame( 'updates.core', $check->id() );
		$this->assertSame( Group::UPDATES, $check->group() );
		$this->assertSame( Schedule::TWICE_DAILY, $check->schedule() );
		$this->assertNotSame( '', $check->label() );
	}

	public function test_no_update_data_is_info_without_findings(): void {
		$result = $this->check_with( 'none' )->run( Context::for_tests() );

		$this->assertSame( Status::INFO, $result->status );
		$this->assertSame( [], $result->findings );
		$this->assertSame( '6.9.1', $result->data['current_version'] );
		$this->assertNull( $result->data['latest_version'] );
	}

	public function test_up_to_date_passes(): void {
		$result = $this->check_with( 'latest' )->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( [], $result->findings );
		$this->assertFalse( $result->data['update_available'] );
		$this->assertSame( 'none', $result->data['update_type'] );
		$this->assertSame( '6.9.1', $result->data['latest_version'] );
		$this->assertSame( gmdate( 'c', 1793520000 ), $result->data['last_checked'] );
	}

	public function test_minor_update_fails_with_high_finding(): void {
		$result = $this->check_with( 'minor' )->run( Context::for_tests() );

		$this->assertSame( Status::FAIL, $result->status );
		$this->assertTrue( $result->data['update_available'] );
		$this->assertSame( 'minor', $result->data['update_type'] );
		$this->assertCount( 1, $result->data['offers'], 'autoupdate offers are ignored' );

		$this->assertCount( 1, $result->findings );
		$this->assertSame( 'core:6.9.2', $result->findings[0]->dedupe_key );
		$this->assertSame( Severity::HIGH, $result->findings[0]->severity );
	}

	public function test_major_update_warns_with_medium_finding_for_highest_version(): void {
		$result = $this->check_with( 'major' )->run( Context::for_tests() );

		$this->assertSame( Status::WARN, $result->status );
		$this->assertSame( 'major', $result->data['update_type'] );
		$this->assertSame( '7.0', $result->data['latest_version'] );
		$this->assertCount( 2, $result->data['offers'] );
		$this->assertSame( 'core:7.0', $result->findings[0]->dedupe_key );
		$this->assertSame( Severity::MEDIUM, $result->findings[0]->severity );
	}

	public function test_result_is_json_serialisable(): void {
		$result = $this->check_with( 'major' )->run( Context::for_tests() );

		$this->assertIsString( wp_json_encode( $result->to_array() ) );
	}
}
