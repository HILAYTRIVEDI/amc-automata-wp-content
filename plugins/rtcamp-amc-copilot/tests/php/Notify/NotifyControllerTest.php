<?php
/**
 * Tests for POST /amc/v1/notify/test.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Notify;

use AMC\Core\Capabilities;
use AMC\Core\Settings;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Class NotifyControllerTest
 */
final class NotifyControllerTest extends NotifyTestCase {

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$engineer = self::factory()->user->create( [ 'role' => 'administrator' ] );
		Capabilities::grant( $engineer );
		wp_set_current_user( $engineer );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * Call the route.
	 *
	 * @param array<string, mixed> $params Body.
	 */
	private function post( array $params = [] ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/amc/v1/notify/test' );
		$request->set_body_params( $params );

		return rest_do_request( $request );
	}

	public function test_immediate_test_email(): void {
		$response = $this->post( [ 'type' => 'immediate' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			[
				'sent'       => true,
				'recipients' => [ 'engineer@example.com' ],
			],
			$response->get_data()
		);
		$this->assertSame( 1, $this->sent_count() );
		$this->assertStringContainsString( 'Test alert', $this->sent()->subject );
	}

	public function test_default_type_is_immediate_and_digest_is_supported(): void {
		$this->post();
		$this->assertStringContainsString( 'Test alert', $this->sent()->subject );

		reset_phpmailer_instance();
		$response = $this->post( [ 'type' => 'digest' ] );

		$this->assertTrue( $response->get_data()['sent'] );
		$this->assertStringContainsString( 'Test: daily digest', $this->sent()->subject );
	}

	public function test_test_email_does_not_touch_real_findings_or_the_throttle(): void {
		$this->post();

		$this->assertSame( 0, ( new \AMC\Notify\Throttle() )->used() );
	}

	public function test_no_recipients_reports_not_sent(): void {
		Settings::update( [ 'recipients' => [] ] );
		delete_user_meta( get_current_user_id(), $GLOBALS['wpdb']->get_blog_prefix() . 'capabilities' );

		$data = $this->post()->get_data();

		$this->assertFalse( $data['sent'] );
		$this->assertSame( [], $data['recipients'] );
	}

	public function test_kill_switch_returns_503(): void {
		Settings::update( [ 'kill_switch' => true ] );

		$response = $this->post();

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 0, $this->sent_count() );
	}

	public function test_invalid_type_is_rejected(): void {
		$this->assertSame( 400, $this->post( [ 'type' => 'bogus' ] )->get_status() );
	}

	public function test_needs_the_manage_capability(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 403, $this->post()->get_status() );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->post()->get_status() );
	}
}
