<?php
/**
 * Tests for POST /amc/v1/settings validation.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Rest;

use AMC\Core\Capabilities;
use AMC\Core\Settings;
use AMC\Tests\TestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class SettingsControllerTest
 */
final class SettingsControllerTest extends TestCase {

	/**
	 * {@inheritDoc}
	 */
	public function set_up(): void {
		parent::set_up();

		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		Capabilities::grant( $admin );
		wp_set_current_user( $admin );
	}

	/**
	 * POST a JSON body to /amc/v1/settings.
	 *
	 * @param array<string, mixed> $body Body.
	 */
	private function post( array $body ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/amc/v1/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );

		return rest_get_server()->dispatch( $request );
	}

	public function test_valid_report_recipients_are_saved_and_returned(): void {
		$response = $this->post( [ 'report_publish_recipients' => [ 'pm@example.com', 'PM@example.com', 'owner@example.org' ] ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'pm@example.com', 'owner@example.org' ], $response->get_data()['report_publish_recipients'] );
		$this->assertSame( [ 'pm@example.com', 'owner@example.org' ], Settings::get_value( 'report_publish_recipients' ) );
	}

	public function test_a_comma_separated_string_is_accepted(): void {
		$response = $this->post( [ 'report_publish_recipients' => "pm@example.com, owner@example.org\nops@example.net" ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'pm@example.com', 'owner@example.org', 'ops@example.net' ], Settings::get_value( 'report_publish_recipients' ) );
	}

	public function test_an_invalid_address_is_rejected_and_nothing_is_saved(): void {
		Settings::update( [ 'report_publish_recipients' => [ 'kept@example.com' ] ] );

		$response = $this->post(
			[
				'report_publish_recipients' => [ 'pm@example.com', 'not-an-email', 'x@y' ],
				'digest_time'               => '07:00',
			]
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'amc_invalid_email', $response->get_data()['code'] );
		$this->assertStringContainsString( 'not-an-email', $response->get_data()['message'] );
		$this->assertContains( 'not-an-email', $response->get_data()['data']['invalid'] );
		$this->assertSame( [ 'kept@example.com' ], Settings::get_value( 'report_publish_recipients' ) );
		$this->assertSame( '09:00', Settings::get_value( 'digest_time' ), 'the whole save is rejected' );
	}

	public function test_a_non_list_value_is_rejected(): void {
		$response = $this->post( [ 'report_publish_recipients' => [ 'nested' => [ 'a@example.com' ] ] ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'amc_invalid_email', $response->get_data()['code'] );

		$response = $this->post( [ 'report_publish_recipients' => 5 ] );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_too_many_addresses_are_rejected(): void {
		$many = [];
		for ( $i = 0; $i <= Settings::MAX_REPORT_PUBLISH_RECIPIENTS; $i++ ) {
			$many[] = "person{$i}@example.com";
		}

		$response = $this->post( [ 'report_publish_recipients' => $many ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'amc_too_many_recipients', $response->get_data()['code'] );
		$this->assertSame( [], Settings::get_value( 'report_publish_recipients' ) );
	}

	public function test_an_empty_list_clears_the_setting(): void {
		Settings::update( [ 'report_publish_recipients' => [ 'pm@example.com' ] ] );

		$this->assertSame( 200, $this->post( [ 'report_publish_recipients' => [] ] )->get_status() );
		$this->assertSame( [], Settings::get_value( 'report_publish_recipients' ) );
	}

	public function test_delete_reports_on_uninstall_is_off_by_default_and_round_trips(): void {
		delete_option( 'amc_delete_reports_on_uninstall' );

		$get = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/amc/v1/settings' ) );
		$this->assertFalse( $get->get_data()['delete_reports_on_uninstall'], 'reports are kept by default' );

		$response = $this->post( [ 'delete_reports_on_uninstall' => true ] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['delete_reports_on_uninstall'] );
		$this->assertTrue( (bool) get_option( 'amc_delete_reports_on_uninstall' ), 'uninstall.php reads this option' );

		$response = $this->post( [ 'delete_reports_on_uninstall' => false ] );
		$this->assertFalse( $response->get_data()['delete_reports_on_uninstall'] );
		$this->assertFalse( get_option( 'amc_delete_reports_on_uninstall' ) );
	}

	public function test_saving_other_settings_leaves_delete_reports_untouched(): void {
		update_option( 'amc_delete_reports_on_uninstall', 1, false );

		$this->post( [ 'digest_time' => '08:00' ] );

		$this->assertTrue( (bool) get_option( 'amc_delete_reports_on_uninstall' ) );
	}
}
