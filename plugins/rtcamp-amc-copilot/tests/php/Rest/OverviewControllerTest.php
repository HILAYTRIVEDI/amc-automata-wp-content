<?php
/**
 * Tests for GET /amc/v1/overview: which runs count, and stale results.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Rest;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Fixtures backdate rows the repositories stamp with the current time.

use AMC\Checks\Finding;
use AMC\Checks\Result;
use AMC\Checks\Schedule;
use AMC\Checks\Severity;
use AMC\Core\Capabilities;
use AMC\Core\Settings;
use AMC\Storage\FindingsRepository;
use AMC\Storage\RunsRepository;
use AMC\Storage\Tables;
use AMC\Tests\Support\FakeCheck;
use AMC\Tests\Support\ReplacesChecks;
use AMC\Tests\TestCase;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Class OverviewControllerTest
 */
final class OverviewControllerTest extends TestCase {

	use ReplacesChecks;

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$engineer = self::factory()->user->create( [ 'role' => 'administrator' ] );
		Capabilities::grant( $engineer );
		wp_set_current_user( $engineer );

		$this->replace_checks(
			new FakeCheck( 'test.hourly', Result::pass( 'ok' ) ),
			new FakeCheck( 'test.weekly', Result::pass( 'ok' ), Schedule::WEEKLY ),
			new FakeCheck( 'test.disabled', Result::pass( 'ok' ) )
		);
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * The overview response data.
	 *
	 * @return array<string, mixed>
	 */
	private function overview(): array {
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/amc/v1/overview' ) )->get_data();
	}

	/**
	 * Store a finished run, optionally backdated.
	 *
	 * @param string $check_id    Check id.
	 * @param string $status      Run status.
	 * @param int    $seconds_ago Age of the run.
	 */
	private function seed_run( string $check_id, string $status, int $seconds_ago = 0 ): void {
		global $wpdb;

		$runs = new RunsRepository();
		$id   = $runs->create_running( $check_id, 'cron' );
		$runs->finish( $id, $status, 'seeded', [], 5 );

		$wpdb->update( Tables::name( Tables::RUNS ), [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - $seconds_ago ) ], [ 'id' => $id ] );
	}

	/**
	 * Disable a check in the settings.
	 *
	 * @param string $check_id Check id.
	 */
	private static function disable( string $check_id ): void {
		Settings::update(
			[
				'checks' => [
					$check_id => [
						'enabled'  => false,
						'interval' => '',
					],
				],
			]
		);
	}

	public function test_a_disabled_check_no_longer_sets_the_overall_status(): void {
		$this->seed_run( 'test.hourly', 'pass' );
		$this->seed_run( 'test.weekly', 'pass' );
		$this->seed_run( 'test.disabled', 'fail' );

		$this->assertSame( 'fail', $this->overview()['status'], 'enabled, it counts' );

		self::disable( 'test.disabled' );

		$this->assertSame( 'pass', $this->overview()['status'] );
	}

	public function test_a_check_that_is_no_longer_registered_is_ignored(): void {
		$this->seed_run( 'test.hourly', 'pass' );
		$this->seed_run( 'gone.check', 'fail' );

		$this->assertSame( 'pass', $this->overview()['status'] );
	}

	public function test_open_findings_of_a_disabled_check_are_not_counted(): void {
		$findings = new FindingsRepository();
		$findings->insert_finding( new Finding( 'a', Severity::HIGH, 'A' ), 'test.hourly', 1 );
		$findings->insert_finding( new Finding( 'b', Severity::HIGH, 'B' ), 'test.disabled', 1 );

		$this->assertSame( 2, $this->overview()['findings_by_severity']['high'] );

		self::disable( 'test.disabled' );

		$this->assertSame( 1, $this->overview()['findings_by_severity']['high'] );
	}

	public function test_results_older_than_two_intervals_are_stale(): void {
		$this->seed_run( 'test.hourly', 'pass', 3 * HOUR_IN_SECONDS );
		$this->seed_run( 'test.weekly', 'pass', 3 * DAY_IN_SECONDS );
		$this->seed_run( 'test.disabled', 'pass', 30 * DAY_IN_SECONDS );
		self::disable( 'test.disabled' );

		$stale = $this->overview()['stale'];

		$this->assertSame( [ 'test.hourly' ], array_column( $stale, 'check_id' ), 'weekly is within two weeks; a disabled check is never stale' );
		$this->assertSame( 'Fake test.hourly', $stale[0]['label'] );
		$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', time() - 3 * HOUR_IN_SECONDS ), $stale[0]['last_run_at'] );
	}

	public function test_fresh_results_are_not_stale(): void {
		$this->seed_run( 'test.hourly', 'pass', HOUR_IN_SECONDS );
		$this->seed_run( 'test.weekly', 'pass', 8 * DAY_IN_SECONDS );

		$this->assertSame( [], $this->overview()['stale'] );
	}
}
