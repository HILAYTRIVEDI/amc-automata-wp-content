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

	public function test_recommended_results_pass_with_a_low_finding_each(): void {
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
		$this->assertSame( 1, $result->data['good'] );
		$this->assertSame( 1, $result->data['recommended'] );
		$this->assertSame( 0, $result->data['critical'] );

		$this->assertCount( 1, $result->findings );
		$this->assertSame( 'site-health:http_response:recommended', $result->findings[0]->dedupe_key );
		$this->assertSame( Severity::LOW, $result->findings[0]->severity );
		$this->assertSame(
			[
				'id'     => 'http_response',
				'status' => 'recommended',
			],
			$result->findings[0]->data
		);
		$this->assertStringContainsString( 'Site health recommendation: HTTP response time', $result->findings[0]->title );
	}

	public function test_critical_and_recommended_results_each_raise_their_own_finding(): void {
		$result = $this->check_with(
			[
				[
					'id'     => 'loopback_requests',
					'label'  => 'Loopback request',
					'status' => 'critical',
				],
				[
					'id'     => 'http_response',
					'label'  => 'HTTP response time',
					'status' => 'recommended',
				],
			]
		)->run( Context::for_tests() );

		$this->assertSame( Status::WARN, $result->status );
		$this->assertSame(
			[ 'site-health:loopback_requests', 'site-health:http_response:recommended' ],
			array_map( static fn ( $finding ): string => $finding->dedupe_key, $result->findings )
		);
		$this->assertSame( [ Severity::MEDIUM, Severity::LOW ], array_map( static fn ( $finding ): Severity => $finding->severity, $result->findings ) );
	}

	public function test_a_test_that_errored_is_counted_but_is_neither_critical_nor_a_finding(): void {
		$result = $this->check_with(
			[
				[
					'id'     => 'php_version',
					'label'  => 'PHP Version',
					'status' => 'error',
				],
				[
					'id'     => 'wordpress_version',
					'label'  => 'WordPress Version',
					'status' => 'good',
				],
			]
		)->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( [], $result->findings );
		$this->assertSame( 1, $result->data['errors'] );
		$this->assertSame( 1, $result->data['good'] );
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

	/**
	 * Run the check with the REAL default source, short-circuiting every HTTP request.
	 */
	private function run_with_the_real_tests(): \AMC\Checks\Result {
		$replay = static function ( $pre, $args, string $url ): array {
			// The PHP version test reads the serve-happy API's answer.
			$body = str_contains( $url, 'serve-happy' )
				? '{"recommended_version":"8.3","minimum_version":"7.4","is_supported":true,"is_secure":true,"is_acceptable":true}'
				: '{}';

			return [
				'headers'  => [],
				'body'     => $body,
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'cookies'  => [],
				'filename' => null,
			];
		};
		add_filter( 'pre_http_request', $replay, 10, 3 );

		try {
			return ( new SiteHealth() )->run( Context::for_tests() );
		} finally {
			remove_filter( 'pre_http_request', $replay );
		}
	}

	public function test_the_default_source_runs_the_real_site_health_tests(): void {
		$result = $this->run_with_the_real_tests();

		$ids = array_column( $result->data['tests'], 'id' );

		$this->assertGreaterThan( 10, count( $result->data['tests'] ) );
		$this->assertSame( 0, $result->data['errors'], 'No Site Health test may crash: ' . implode( ', ', array_column( array_filter( $result->data['tests'], static fn ( array $t ): bool => 'error' === $t['status'] ), 'id' ) ) );
		$this->assertSame(
			count( $result->data['tests'] ),
			$result->data['good'] + $result->data['recommended'] + $result->data['critical']
		);

		// Direct tests, async tests that can run directly, but neither skip_cron tests nor page_cache.
		$this->assertContains( 'wordpress_version', $ids );
		$this->assertContains( 'php_version', $ids );
		$this->assertContains( 'dotorg_communication', $ids );
		$this->assertContains( 'loopback_requests', $ids );
		$this->assertNotContains( 'page_cache', $ids );
		$this->assertNotContains( 'rest_availability', $ids );
		$this->assertNotContains( 'authorization_header', $ids );

		foreach ( $result->data['tests'] as $test ) {
			$this->assertContains( $test['status'], [ 'good', 'recommended', 'critical' ], $test['id'] );
			$this->assertNotSame( '', $test['label'], $test['id'] );
		}
	}

	public function test_the_default_source_applies_the_site_status_test_result_filter(): void {
		$relabel = static function ( $result ) {
			if ( is_array( $result ) && 'wordpress_version' === ( $result['test'] ?? '' ) ) {
				$result['label']  = 'Relabelled by a plugin';
				$result['status'] = 'critical';
			}

			return $result;
		};
		add_filter( 'site_status_test_result', $relabel );

		try {
			$result = $this->run_with_the_real_tests();
		} finally {
			remove_filter( 'site_status_test_result', $relabel );
		}

		$by_id = array_column( $result->data['tests'], null, 'id' );

		$this->assertSame( 'Relabelled by a plugin', $by_id['wordpress_version']['label'] );
		$this->assertSame( 'critical', $by_id['wordpress_version']['status'] );
		$this->assertContains( 'site-health:wordpress_version', array_map( static fn ( $finding ): string => $finding->dedupe_key, $result->findings ) );
	}

	public function test_one_test_that_throws_does_not_stop_the_others(): void {
		$crash = static function ( $result ) {
			if ( is_array( $result ) && 'php_version' === ( $result['test'] ?? '' ) ) {
				throw new \RuntimeException( 'A plugin broke this test.' );
			}

			return $result;
		};
		add_filter( 'site_status_test_result', $crash );

		try {
			$result = $this->run_with_the_real_tests();
		} finally {
			remove_filter( 'site_status_test_result', $crash );
		}

		$by_id = array_column( $result->data['tests'], null, 'id' );

		$this->assertSame( 'error', $by_id['php_version']['status'] );
		$this->assertSame( 1, $result->data['errors'] );
		$this->assertGreaterThan( 10, count( $result->data['tests'] ) );
		$this->assertArrayHasKey( 'wordpress_version', $by_id );
	}
}
