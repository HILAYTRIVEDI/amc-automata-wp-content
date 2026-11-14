<?php
/**
 * Tests for the health.site_health check.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Checks\Health;

use AMC\Checks\Context;
use AMC\Checks\Group;
use AMC\Checks\Health\SiteHealth;
use AMC\Checks\Schedule;
use AMC\Checks\Severity;
use AMC\Checks\Status;
use AMC\Tests\TestCase;

/**
 * Class SiteHealthTest
 *
 * Pattern (from the CoreUpdatesTest exemplar): build the check with a fake
 * tests source, call run() and assert status, data and findings.
 */
final class SiteHealthTest extends TestCase {

	/**
	 * Build the check with a canned list of direct test results.
	 *
	 * @param array<int, mixed> $tests Direct test results.
	 */
	private function check_with( array $tests ): SiteHealth {
		return new SiteHealth( static fn (): array => $tests );
	}

	public function test_metadata(): void {
		$check = new SiteHealth();

		$this->assertSame( 'health.site_health', $check->id() );
		$this->assertSame( Group::HEALTH, $check->group() );
		$this->assertSame( Schedule::DAILY, $check->schedule() );
		$this->assertNotSame( '', $check->label() );
	}

	public function test_all_good_results_pass_without_findings(): void {
		$result = $this->check_with(
			[
				[
					'id'     => 'dotorg_communication',
					'label'  => 'Communication with WordPress.org',
					'status' => 'good',
				],
				[
					'id'     => 'loopback_requests',
					'label'  => 'Loopback request',
					'status' => 'good',
				],
			]
		)->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( [], $result->findings );
		$this->assertSame( 2, $result->data['good'] );
		$this->assertSame( 0, $result->data['recommended'] );
		$this->assertSame( 0, $result->data['critical'] );
		$this->assertStringContainsString( 'No critical site health results', $result->summary );
	}

	public function test_recommended_results_still_pass(): void {
		$result = $this->check_with(
			[
				[
					'id'     => 'dotorg_communication',
					'label'  => 'Communication with WordPress.org',
					'status' => 'good',
				],
				[
					'id'     => 'http_response',
					'label'  => 'HTTP response time',
					'status' => 'recommended',
				],
			]
		)->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( [], $result->findings );
		$this->assertSame( 1, $result->data['good'] );
		$this->assertSame( 1, $result->data['recommended'] );
		$this->assertSame( 0, $result->data['critical'] );
	}

	public function test_critical_results_warn_with_one_finding_each(): void {
		$result = $this->check_with(
			[
				[
					'id'     => 'dotorg_communication',
					'label'  => 'Communication with WordPress.org',
					'status' => 'good',
				],
				[
					'id'     => 'loopback_requests',
					'label'  => 'Loopback request',
					'status' => 'critical',
				],
				[
					'id'     => 'https_status',
					'label'  => 'HTTPS status',
					'status' => 'critical',
				],
			]
		)->run( Context::for_tests() );

		$this->assertSame( Status::WARN, $result->status );
		$this->assertSame( 1, $result->data['good'] );
		$this->assertSame( 0, $result->data['recommended'] );
		$this->assertSame( 2, $result->data['critical'] );

		$this->assertCount( 2, $result->findings );
		$this->assertSame( 'site-health:loopback_requests', $result->findings[0]->dedupe_key );
		$this->assertSame( 'site-health:https_status', $result->findings[1]->dedupe_key );
		$this->assertSame( Severity::MEDIUM, $result->findings[0]->severity );
		$this->assertSame( 'loopback_requests', $result->findings[0]->data['id'] );
		$this->assertSame( 'critical', $result->findings[0]->data['status'] );
		$this->assertStringContainsString( 'critical site health result', $result->summary );
		$this->assertStringContainsString( 'Site health critical: Loopback request', $result->findings[0]->title );
	}

	public function test_unknown_status_counts_as_critical(): void {
		$result = $this->check_with(
			[
				[
					'id'     => 'loopback_requests',
					'label'  => 'Loopback request',
					'status' => 'debug',
				],
			]
		)->run( Context::for_tests() );

		$this->assertSame( Status::WARN, $result->status );
		$this->assertSame( 0, $result->data['good'] );
		$this->assertSame( 0, $result->data['recommended'] );
		$this->assertSame( 1, $result->data['critical'] );
	}

	public function test_malformed_rows_are_skipped(): void {
		$result = $this->check_with(
			[
				'not-an-array',
				[
					'id'     => 'dotorg_communication',
					'label'  => 'Communication with WordPress.org',
					'status' => 'good',
				],
			]
		)->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( 1, $result->data['good'] );
	}

	public function test_result_is_json_serialisable(): void {
		$result = $this->check_with(
			[
				[
					'id'     => 'loopback_requests',
					'label'  => 'Loopback request',
					'status' => 'critical',
				],
			]
		)->run( Context::for_tests() );

		$this->assertIsString( wp_json_encode( $result->to_array() ) );
	}
}
