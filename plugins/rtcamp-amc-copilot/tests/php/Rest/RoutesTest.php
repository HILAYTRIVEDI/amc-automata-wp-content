<?php
/**
 * Tests for the amc/v1 route map: registration, permissions, shapes.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Rest;

use AMC\Checks\Result;
use AMC\Core\Capabilities;
use AMC\Storage\RunsRepository;
use AMC\Tests\Support\FakeCheck;
use AMC\Tests\TestCase;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Class RoutesTest
 */
final class RoutesTest extends TestCase {

	/**
	 * Every route of the frozen route map => methods. Mirrors docs/CONTRACTS.md § REST.
	 */
	private const ROUTES = [
		'/amc/v1/overview'                           => [ 'GET' ],
		'/amc/v1/checks'                             => [ 'GET' ],
		'/amc/v1/checks/run'                         => [ 'POST' ],
		'/amc/v1/runs'                               => [ 'GET' ],
		'/amc/v1/runs/(?P<id>\d+)'                   => [ 'GET' ],
		'/amc/v1/runs/(?P<id>\d+)/rerun'             => [ 'POST' ],
		'/amc/v1/findings'                           => [ 'GET' ],
		'/amc/v1/findings/(?P<id>\d+)'               => [ 'GET' ],
		'/amc/v1/findings/(?P<id>\d+)/status'        => [ 'POST' ],
		'/amc/v1/settings'                           => [ 'GET', 'POST' ],
		'/amc/v1/access'                             => [ 'GET', 'POST' ],
		'/amc/v1/access/(?P<user_id>\d+)'            => [ 'DELETE' ],
		'/amc/v1/updates'                            => [ 'GET' ],
		'/amc/v1/inventory'                          => [ 'GET' ],
		'/amc/v1/vulnerabilities'                    => [ 'GET' ],
		'/amc/v1/vulnerabilities/(?P<id>\d+)/accept' => [ 'POST' ],
		'/amc/v1/security'                           => [ 'GET' ],
		'/amc/v1/performance'                        => [ 'GET' ],
		'/amc/v1/smoke'                              => [ 'GET' ],
		'/amc/v1/smoke/urls'                         => [ 'POST' ],
		'/amc/v1/errors'                             => [ 'GET' ],
		'/amc/v1/changes'                            => [ 'GET' ],
		'/amc/v1/notify/test'                        => [ 'POST' ],
		'/amc/v1/reports'                            => [ 'GET' ],
		'/amc/v1/reports/generate'                   => [ 'POST' ],
		'/amc/v1/reports/(?P<id>\d+)/regenerate'     => [ 'POST' ],
		'/amc/v1/reports/(?P<id>\d+)/snapshot'       => [ 'GET' ],
		'/amc/v1/saved-fields'                       => [ 'GET', 'POST' ],
	];

	/**
	 * Engineer user ID.
	 *
	 * @var int
	 */
	private int $engineer;

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->engineer = self::factory()->user->create( [ 'role' => 'administrator' ] );
		Capabilities::grant( $this->engineer );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * Dispatch a request.
	 *
	 * @param string               $method Method.
	 * @param string               $path   Path.
	 * @param array<string, mixed> $params Params.
	 */
	private function request( string $method, string $path, array $params = [] ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, $path );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	public function test_every_route_is_registered_with_its_methods(): void {
		$routes = rest_get_server()->get_routes( 'amc/v1' );

		foreach ( self::ROUTES as $route => $methods ) {
			$this->assertArrayHasKey( $route, $routes, "Missing route {$route}" );

			$registered = [];
			foreach ( $routes[ $route ] as $handler ) {
				$registered = array_merge( $registered, array_keys( $handler['methods'] ) );
			}
			foreach ( $methods as $method ) {
				$this->assertContains( $method, $registered, "{$method} {$route}" );
			}
		}
	}

	public function test_anonymous_and_non_engineers_are_refused(): void {
		$this->assertSame( 401, $this->request( 'GET', '/amc/v1/overview' )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 403, $this->request( 'GET', '/amc/v1/overview' )->get_status(), 'Administrators without amc_manage are clients' );
		$this->assertSame( 200, $this->request( 'GET', '/amc/v1/reports' )->get_status(), 'Clients can list published reports' );
	}

	public function test_engineer_reads_every_get_route(): void {
		wp_set_current_user( $this->engineer );

		foreach ( [ 'overview', 'checks', 'runs', 'findings', 'settings', 'access', 'updates', 'inventory', 'vulnerabilities', 'security', 'performance', 'smoke', 'errors', 'changes', 'reports', 'saved-fields' ] as $route ) {
			$this->assertSame( 200, $this->request( 'GET', "/amc/v1/{$route}" )->get_status(), $route );
		}
	}

	public function test_overview_shape(): void {
		wp_set_current_user( $this->engineer );

		$data = $this->request( 'GET', '/amc/v1/overview' )->get_data();

		foreach ( [ 'status', 'kill_switch', 'findings_by_severity', 'groups', 'versions', 'counts', 'next_runs' ] as $key ) {
			$this->assertArrayHasKey( $key, $data );
		}
		$this->assertCount( 11, $data['groups'] );
	}

	public function test_run_now_queues_without_running_and_poll_returns_the_run(): void {
		wp_set_current_user( $this->engineer );
		$check = new FakeCheck( 'test.rest', Result::pass( 'ok' ) );
		$this->register_checks( $check );

		$response = $this->request( 'POST', '/amc/v1/checks/run', [ 'check_ids' => [ 'test.rest' ] ] );

		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( [], $check->contexts, 'The check must not run inside the REST request' );

		$run_id = $response->get_data()['runs'][0]['run_id'];
		$poll   = $this->request( 'GET', "/amc/v1/runs/{$run_id}" )->get_data();
		$this->assertSame( 'queued', $poll['status'] );
		$this->assertSame( 'manual', $poll['trigger'] );
		$this->assertArrayHasKey( 'findings', $poll );
	}

	public function test_run_now_with_unknown_check_is_404(): void {
		wp_set_current_user( $this->engineer );

		$this->assertSame( 404, $this->request( 'POST', '/amc/v1/checks/run', [ 'check_ids' => [ 'nope.nope' ] ] )->get_status() );
	}

	public function test_runs_list_has_pagination_headers(): void {
		wp_set_current_user( $this->engineer );
		$runs = new RunsRepository();
		$runs->finish( $runs->create_running( 'updates.core', 'cron' ), 'pass', 'ok', [], 1 );

		$response = $this->request( 'GET', '/amc/v1/runs' );

		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( 'updates.core', $response->get_data()[0]['check_id'] );
		$this->assertMatchesRegularExpression( '/Z$/', $response->get_data()[0]['created_at'] );
	}

	public function test_other_lanes_write_routes_return_501(): void {
		wp_set_current_user( $this->engineer );

		$this->assertSame( 501, $this->request( 'POST', '/amc/v1/reports/generate', [ 'period_type' => 'month' ] )->get_status() );
		$this->assertSame( 501, $this->request( 'POST', '/amc/v1/saved-fields', [ 'fields' => [ 'x' => 1 ] ] )->get_status() );
	}

	public function test_lane_b2_screens_are_no_longer_stubs(): void {
		wp_set_current_user( $this->engineer );

		$this->assertArrayNotHasKey( 'X-AMC-Stub', $this->request( 'GET', '/amc/v1/smoke' )->get_headers() );
		$this->assertArrayNotHasKey( 'X-AMC-Stub', $this->request( 'GET', '/amc/v1/performance' )->get_headers() );
	}

	public function test_settings_update_needs_manage_options(): void {
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		Capabilities::grant( $editor );
		wp_set_current_user( $editor );

		$this->assertSame( 200, $this->request( 'GET', '/amc/v1/settings' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/amc/v1/settings' )->get_status() );
	}

	public function test_access_grant_and_revoke(): void {
		wp_set_current_user( $this->engineer );
		$user = self::factory()->user->create();

		$this->assertSame( 201, $this->request( 'POST', '/amc/v1/access', [ 'user_id' => $user ] )->get_status() );
		$this->assertTrue( user_can( $user, Capabilities::MANAGE ) );

		$this->assertSame( 200, $this->request( 'DELETE', "/amc/v1/access/{$user}" )->get_status() );
		$this->assertFalse( user_can( $user, Capabilities::MANAGE ) );

		$this->assertSame( 400, $this->request( 'DELETE', "/amc/v1/access/{$this->engineer}" )->get_status(), 'No self-revoke' );
	}

	public function test_finding_status_change(): void {
		wp_set_current_user( $this->engineer );
		$id = ( new \AMC\Storage\FindingsRepository() )->insert_finding( new \AMC\Checks\Finding( 'k', \AMC\Checks\Severity::HIGH, 'T' ), 'vuln.match', 1 );

		$data = $this->request(
			'POST',
			"/amc/v1/findings/{$id}/status",
			[
				'status' => 'accepted',
				'note'   => 'Vendor fix due',
			]
		)->get_data();

		$this->assertSame( 'accepted', $data['status'] );
		$this->assertSame( 'Vendor fix due', $data['note'] );
	}
}
