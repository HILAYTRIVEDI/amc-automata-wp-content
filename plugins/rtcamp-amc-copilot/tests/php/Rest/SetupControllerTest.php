<?php
/**
 * Tests for /amc/v1/setup.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Rest;

use AMC\Admin\SetupDefaults;
use AMC\Admin\SetupState;
use AMC\Core\Capabilities;
use AMC\Storage\RunsRepository;
use AMC\Tests\TestCase;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Class SetupControllerTest
 */
final class SetupControllerTest extends TestCase {

	/**
	 * Engineer administrator.
	 *
	 * @var int
	 */
	private int $engineer;

	public function set_up(): void {
		parent::set_up();

		delete_option( SetupState::OPTION );

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
	 * @param string $method Method.
	 * @param string $path   Path.
	 */
	private function request( string $method, string $path ): \WP_REST_Response {
		return rest_get_server()->dispatch( new WP_REST_Request( $method, $path ) );
	}

	public function test_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes( 'amc/v1' );

		$this->assertArrayHasKey( '/amc/v1/setup', $routes );
		$this->assertArrayHasKey( '/amc/v1/setup/complete', $routes );
		$this->assertArrayHasKey( '/amc/v1/setup/dismiss', $routes );
	}

	public function test_needs_amc_manage_and_manage_options(): void {
		$this->assertSame( 401, $this->request( 'GET', '/amc/v1/setup' )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 403, $this->request( 'GET', '/amc/v1/setup' )->get_status(), 'Administrator without amc_manage' );
		$this->assertSame( 403, $this->request( 'POST', '/amc/v1/setup/complete' )->get_status() );

		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		Capabilities::grant( $editor );
		wp_set_current_user( $editor );
		$this->assertSame( 403, $this->request( 'GET', '/amc/v1/setup' )->get_status(), 'Engineer without manage_options' );
		$this->assertSame( 403, $this->request( 'POST', '/amc/v1/setup/dismiss' )->get_status() );

		$this->assertFalse( SetupState::is_completed() );
	}

	public function test_get_returns_state_and_detected_values(): void {
		wp_set_current_user( $this->engineer );
		update_option( 'blogname', 'Example Client' );

		$response = $this->request( 'GET', '/amc/v1/setup' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $data['completed'] );
		$this->assertNull( $data['completed_at'] );
		$this->assertFalse( $data['notice_dismissed'] );

		foreach ( [ 'environment', 'site_name', 'home_url', 'primary_domain', 'admin_email', 'engineer_emails', 'host', 'ssl', 'suggestions' ] as $key ) {
			$this->assertArrayHasKey( $key, $data['detected'], $key );
		}
		$this->assertSame( 'Example Client', $data['detected']['suggestions']['overview']['client_name'] );
		$this->assertContains( get_userdata( $this->engineer )->user_email, $data['detected']['engineer_emails'] );
	}

	public function test_detected_hosting_and_ssl_come_from_the_latest_runs(): void {
		wp_set_current_user( $this->engineer );

		$runs = new RunsRepository();
		$id   = $runs->create_running( 'hosting.platform', 'manual' );
		$runs->finish(
			$id,
			'pass',
			'WP Engine',
			[
				'host'        => 'wpengine',
				'web_server'  => 'nginx',
				'php_version' => '8.3.1',
			],
			5
		);
		$id = $runs->create_running( 'ssl.certificate', 'manual' );
		$runs->finish(
			$id,
			'pass',
			'Valid',
			[
				'available' => true,
				'issuer'    => "Let's Encrypt",
				'valid_to'  => '2027-01-01T00:00:00Z',
			],
			5
		);

		$detected = $this->request( 'GET', '/amc/v1/setup' )->get_data()['detected'];

		$this->assertSame( 'wpengine', $detected['host']['slug'] );
		$this->assertSame( SetupDefaults::HOST_LABELS['wpengine'], $detected['host']['label'] );
		$this->assertSame( 'nginx', $detected['host']['web_server'] );
		$this->assertSame( "Let's Encrypt", $detected['ssl']['issuer'] );
		$this->assertSame( SetupDefaults::HOST_LABELS['wpengine'], $detected['suggestions']['hosting']['hosting_type'] );
		$this->assertSame( "Free (Let's Encrypt)", $detected['suggestions']['ssl']['ssl_type'] );
	}

	public function test_without_a_stored_run_hosting_is_detected_without_storing_a_run(): void {
		wp_set_current_user( $this->engineer );

		$detected = $this->request( 'GET', '/amc/v1/setup' )->get_data()['detected'];

		$this->assertSame( PHP_VERSION, $detected['host']['php_version'] );
		$this->assertNull( $detected['ssl'] );
		$this->assertArrayNotHasKey( 'hosting.platform', ( new RunsRepository() )->latest_per_check(), 'Detection is not stored as a run.' );
	}

	public function test_complete_marks_setup_finished(): void {
		wp_set_current_user( $this->engineer );

		$response = $this->request( 'POST', '/amc/v1/setup/complete' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['completed'] );
		$this->assertTrue( SetupState::is_completed() );
		$this->assertSame( $this->engineer, get_option( SetupState::OPTION )['user_id'] );
		$this->assertTrue( $this->request( 'GET', '/amc/v1/setup' )->get_data()['completed'] );
	}

	public function test_dismiss_hides_the_notice_for_the_current_user_only(): void {
		wp_set_current_user( $this->engineer );

		$response = $this->request( 'POST', '/amc/v1/setup/dismiss' );

		$this->assertTrue( $response->get_data()['notice_dismissed'] );
		$this->assertTrue( SetupState::is_dismissed( $this->engineer ) );
		$this->assertFalse( SetupState::is_completed(), 'Dismissing is not completing.' );
	}

	public function test_suggestions_only_include_known_values(): void {
		$suggestions = SetupDefaults::suggestions( '', '', null, null );

		$this->assertSame( [ 'overview' => [ 'maintenance_schedule' => 'Monthly' ] ], $suggestions );

		$suggestions = SetupDefaults::suggestions(
			'Acme',
			'acme.test',
			'some-host',
			[
				'issuer'   => 'DigiCert Inc',
				'valid_to' => null,
			]
		);

		$this->assertSame( 'Acme', $suggestions['overview']['client_name'] );
		$this->assertSame( 'acme.test', $suggestions['cms']['websites_covered'] );
		$this->assertArrayNotHasKey( 'hosting', $suggestions, 'An unknown host slug is not a hosting type.' );
		$this->assertArrayNotHasKey( 'ssl', $suggestions, 'A paid issuer is not guessed.' );
	}
}
