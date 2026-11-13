<?php
/**
 * Tests for the lane B2 REST handlers (vulnerabilities, security, performance, smoke).
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Rest;

use AMC\Checks\Finding;
use AMC\Checks\Result;
use AMC\Checks\Severity;
use AMC\Core\Capabilities;
use AMC\Storage\FindingsRepository;
use AMC\Storage\RunsRepository;
use AMC\Tests\TestCase;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Class LaneB2RoutesTest
 */
final class LaneB2RoutesTest extends TestCase {

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

		$this->engineer = self::factory()->user->create( [ 'role' => 'editor' ] );
		Capabilities::grant( $this->engineer );
		wp_set_current_user( $this->engineer );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * Dispatch.
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

	/**
	 * Store a finished run and return its ID.
	 *
	 * @param string               $check_id Check.
	 * @param array<string, mixed> $data     Data.
	 */
	private function store_run( string $check_id, array $data ): int {
		$repo = new RunsRepository();
		$id   = $repo->create_running( $check_id, 'cli' );
		$repo->finish( $id, 'pass', 'ok', $data, 5 );

		return $id;
	}

	/**
	 * Insert a finding.
	 *
	 * @param string   $check_id Check.
	 * @param string   $key      Dedupe key.
	 * @param Severity $severity Severity.
	 */
	private function add_finding( string $check_id, string $key, Severity $severity = Severity::HIGH ): int {
		return ( new FindingsRepository() )->insert_finding( new Finding( $key, $severity, 'T ' . $key ), $check_id, 1 );
	}

	public function test_accepting_a_vulnerability_needs_a_note_and_stores_it(): void {
		$id = $this->add_finding( 'vuln.match', 'plugin:x:1.0:CVE-1' );

		$this->assertSame( 400, $this->request( 'POST', "/amc/v1/vulnerabilities/{$id}/accept" )->get_status(), 'The note is required' );
		$this->assertSame( 400, $this->request( 'POST', "/amc/v1/vulnerabilities/{$id}/accept", [ 'note' => '   ' ] )->get_status() );

		$response = $this->request( 'POST', "/amc/v1/vulnerabilities/{$id}/accept", [ 'note' => 'Not reachable, plugin is inactive.' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'accepted', $response->get_data()['status'] );
		$this->assertSame( 'Not reachable, plugin is inactive.', $response->get_data()['note'] );

		$screen = $this->request( 'GET', '/amc/v1/vulnerabilities', [ 'status' => 'accepted' ] )->get_data();
		$this->assertCount( 1, $screen['findings'] );
		$this->assertSame( 1, $screen['counts']['accepted'] );
		$this->assertSame( 0, $screen['counts']['open']['high'] );
	}

	public function test_only_vulnerability_findings_can_be_accepted_here(): void {
		$other = $this->add_finding( 'security.config', 'config:x' );

		$this->assertSame( 404, $this->request( 'POST', "/amc/v1/vulnerabilities/{$other}/accept", [ 'note' => 'x' ] )->get_status() );
		$this->assertSame( 404, $this->request( 'POST', '/amc/v1/vulnerabilities/999999/accept', [ 'note' => 'x' ] )->get_status() );
	}

	public function test_accepting_is_for_engineers_only(): void {
		$id = $this->add_finding( 'vuln.match', 'plugin:x:1.0:CVE-1' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertSame( 403, $this->request( 'POST', "/amc/v1/vulnerabilities/{$id}/accept", [ 'note' => 'x' ] )->get_status() );
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( 'POST', "/amc/v1/vulnerabilities/{$id}/accept", [ 'note' => 'x' ] )->get_status() );
	}

	public function test_vulnerability_counts_by_severity(): void {
		$this->add_finding( 'vuln.match', 'a', Severity::CRITICAL );
		$this->add_finding( 'vuln.match', 'b', Severity::LOW );
		$this->add_finding( 'vuln.match', 'c', Severity::LOW );

		$counts = $this->request( 'GET', '/amc/v1/vulnerabilities' )->get_data()['counts'];

		$this->assertSame( 1, $counts['open']['critical'] );
		$this->assertSame( 2, $counts['open']['low'] );
	}

	public function test_security_lists_open_findings_of_its_checks_only(): void {
		$this->add_finding( 'ssl.certificate', 'ssl:1' );
		$this->add_finding( 'updates.core', 'core:1' );

		$data = $this->request( 'GET', '/amc/v1/security' )->get_data();

		$this->assertSame( [ 'ssl:1' ], array_column( $data['open_findings'], 'dedupe_key' ) );
		$this->assertArrayHasKey( 'security.checksums', $data['checks'] );
	}

	public function test_performance_trend_comes_from_the_response_check_and_honours_days(): void {
		$today = gmdate( 'Y-m-d' );
		$old   = gmdate( 'Y-m-d', time() - 20 * DAY_IN_SECONDS );

		$this->store_run(
			'perf.response',
			[
				'trend' => [
					[
						'date'      => $old,
						'avg_ms'    => 400,
						'url_count' => 3,
					],
					[
						'date'      => $today,
						'avg_ms'    => 500,
						'url_count' => 3,
					],
				],
			]
		);

		$all = $this->request( 'GET', '/amc/v1/performance', [ 'days' => 30 ] );
		$this->assertCount( 2, $all->get_data()['response_trend'] );
		$this->assertArrayNotHasKey( 'X-AMC-Stub', $all->get_headers() );

		$recent = $this->request( 'GET', '/amc/v1/performance', [ 'days' => 7 ] )->get_data();
		$this->assertSame( [ $today ], array_column( $recent['response_trend'], 'date' ) );
	}

	public function test_smoke_screen_reads_the_last_run_and_lists_unchecked_extra_urls(): void {
		\AMC\Core\Settings::update(
			[
				'smoke_urls' => [
					[
						'url'           => 'https://example.test/pricing',
						'required_text' => 'Plans',
					],
					[
						'url'           => 'https://example.test/new',
						'required_text' => '',
					],
				],
			]
		);

		$day = gmdate( 'Y-m-d' );
		$this->store_run(
			'smoke.urls',
			[
				'urls' => [
					[
						'url'           => 'https://example.test/',
						'required_text' => '',
						'is_default'    => true,
						'status'        => 'pass',
						'http_code'     => 200,
						'response_ms'   => 420,
						'daily'         => [
							$day => [
								'n'       => 4,
								'ok'      => 3,
								'blocked' => 1,
								'ms_sum'  => 1200,
								'ms_n'    => 3,
							],
						],
					],
					[
						'url'           => 'https://example.test/pricing',
						'required_text' => 'old text',
						'is_default'    => false,
						'status'        => 'fail',
						'http_code'     => 500,
						'response_ms'   => 90,
						'daily'         => [
							$day => [
								'n'       => 2,
								'ok'      => 1,
								'blocked' => 0,
								'ms_sum'  => 200,
								'ms_n'    => 2,
							],
						],
					],
				],
			]
		);

		$data = $this->request( 'GET', '/amc/v1/smoke' )->get_data();
		$urls = array_column( $data['urls'], null, 'url' );

		$this->assertNotNull( $data['last_run'] );
		$this->assertCount( 3, $urls );

		$home = $urls['https://example.test/'];
		$this->assertTrue( $home['is_default'] );
		$this->assertSame( 'pass', $home['last_status'] );
		$this->assertSame( 200, $home['last_http_code'] );
		$this->assertSame( 420, $home['last_response_ms'] );
		$this->assertSame( 1.0, $home['pass_rate_30d'], '3 of 3 judged: the blocked check is not counted' );
		$this->assertSame(
			[
				'at' => $day . 'T00:00:00Z',
				'ms' => 400,
				'ok' => true,
			],
			$home['trend'][0]
		);

		$pricing = $urls['https://example.test/pricing'];
		$this->assertSame( 'Plans', $pricing['required_text'], 'Settings is the source of truth for the required text' );
		$this->assertSame( 0.5, $pricing['pass_rate_30d'] );
		$this->assertFalse( $pricing['trend'][0]['ok'] );

		$new = $urls['https://example.test/new'];
		$this->assertNull( $new['last_status'] );
		$this->assertNull( $new['pass_rate_30d'] );
		$this->assertSame( [], $new['trend'] );
	}

	public function test_engineers_can_add_and_edit_smoke_urls_without_manage_options(): void {
		$response = $this->request(
			'POST',
			'/amc/v1/smoke/urls',
			[
				'url'           => 'https://example.test/checkout',
				'required_text' => 'Cart',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			[
				[
					'url'           => 'https://example.test/checkout',
					'required_text' => 'Cart',
				],
			],
			$response->get_data()['urls']
		);

		$edit = $this->request(
			'POST',
			'/amc/v1/smoke/urls',
			[
				'url'           => 'https://example.test/checkout',
				'required_text' => 'Your basket',
			]
		);
		$this->assertCount( 1, $edit->get_data()['urls'], 'Matched by URL: an edit, not a second row' );
		$this->assertSame( 'Your basket', $edit->get_data()['urls'][0]['required_text'] );

		$this->assertSame( 'Your basket', $this->request( 'GET', '/amc/v1/smoke' )->get_data()['urls'][0]['required_text'] );
	}

	public function test_smoke_url_validation_and_permissions(): void {
		$this->assertSame( 400, $this->request( 'POST', '/amc/v1/smoke/urls', [ 'url' => 'javascript:alert(1)' ] )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/amc/v1/smoke/urls', [ 'url' => 'not a url' ] )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/amc/v1/smoke/urls' )->get_status() );

		for ( $i = 0; $i < 25; $i++ ) {
			$this->assertSame( 200, $this->request( 'POST', '/amc/v1/smoke/urls', [ 'url' => "https://example.test/p{$i}" ] )->get_status() );
		}
		$this->assertSame( 400, $this->request( 'POST', '/amc/v1/smoke/urls', [ 'url' => 'https://example.test/one-too-many' ] )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 403, $this->request( 'POST', '/amc/v1/smoke/urls', [ 'url' => 'https://example.test/x' ] )->get_status() );
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( 'POST', '/amc/v1/smoke/urls', [ 'url' => 'https://example.test/x' ] )->get_status() );
	}

	public function test_results_are_json_serialisable(): void {
		$this->assertIsString( wp_json_encode( Result::pass( 'x', [ 'a' => 1 ] )->to_array() ) );
		$this->assertIsString( wp_json_encode( $this->request( 'GET', '/amc/v1/smoke' )->get_data() ) );
	}
}
