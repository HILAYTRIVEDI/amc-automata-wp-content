<?php
/**
 * Tests for the amc_settings option.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Core;

use AMC\Core\Capabilities;
use AMC\Core\KillSwitch;
use AMC\Core\Settings;
use AMC\Tests\TestCase;

/**
 * Class SettingsTest
 */
final class SettingsTest extends TestCase {

	public function test_defaults_are_returned_when_nothing_is_stored(): void {
		$settings = Settings::get();

		$this->assertSame( Settings::defaults(), $settings );
		$this->assertSame( '09:00', $settings['digest_time'] );
		$this->assertSame( 13, $settings['retention_months'] );
		$this->assertFalse( $settings['kill_switch'] );
	}

	public function test_update_merges_sanitises_and_drops_unknown_keys(): void {
		$saved = Settings::update(
			[
				'digest_time'    => '25:99',
				'recipients'     => [ 'ops@example.com', 'not-an-email', 'ops@example.com' ],
				'contract_start' => '2026-02-30',
				'site_type'      => 'ecommerce',
				'unknown'        => 'x',
				'checks'         => [
					'updates.core' => [
						'enabled'  => false,
						'interval' => 'daily',
					],
					'bad id'       => [ 'enabled' => false ],
				],
				'smoke_urls'     => [
					[
						'url'           => 'https://example.com/shop',
						'required_text' => '<b>Cart</b>',
					],
				],
			]
		);

		$this->assertSame( '09:00', $saved['digest_time'] );
		$this->assertSame( [ 'ops@example.com' ], $saved['recipients'] );
		$this->assertSame( '', $saved['contract_start'] );
		$this->assertSame( 'ecommerce', $saved['site_type'] );
		$this->assertArrayNotHasKey( 'unknown', $saved );
		$this->assertSame( [ 'updates.core' ], array_keys( $saved['checks'] ) );
		$this->assertSame( 'Cart', $saved['smoke_urls'][0]['required_text'] );
		$this->assertSame( 'daily', Settings::check_interval( 'updates.core' ) );
		$this->assertFalse( Settings::check_enabled( 'updates.core' ) );
		$this->assertTrue( Settings::check_enabled( 'updates.plugins' ) );
	}

	public function test_loopback_password_is_encrypted_and_hidden_from_rest(): void {
		if ( ! defined( 'AMC_COPILOT_ENCRYPTION_KEY' ) ) {
			define( 'AMC_COPILOT_ENCRYPTION_KEY', 'phpunit-only-key' );
		}

		Settings::update(
			[
				'loopback_auth' => [
					'username' => 'staging',
					'password' => 's3cret',
				],
			]
		);

		$stored = get_option( Settings::OPTION );
		$this->assertNotSame( 's3cret', $stored['loopback_auth']['password'] );
		$this->assertSame( 's3cret', Settings::loopback_password() );

		$rest = Settings::for_rest();
		$this->assertSame(
			[
				'username'             => 'staging',
				'has_password'         => true,
				'encryption_available' => true,
			],
			$rest['loopback_auth']
		);

		// An update without a password keeps the stored one.
		Settings::update( [ 'loopback_auth' => [ 'username' => 'staging2' ] ] );
		$this->assertSame( 's3cret', Settings::loopback_password() );

		// null clears it.
		Settings::update( [ 'loopback_auth' => [ 'password' => null ] ] );
		$this->assertSame( '', Settings::loopback_password() );
	}

	public function test_recipients_fall_back_to_engineers(): void {
		$user = self::factory()->user->create_and_get( [ 'user_email' => 'eng@example.com' ] );
		Capabilities::grant( $user->ID );

		$this->assertContains( 'eng@example.com', Settings::recipients() );

		Settings::update( [ 'recipients' => [ 'client@example.com' ] ] );
		$this->assertSame( [ 'client@example.com' ], Settings::recipients() );
	}

	public function test_kill_switch_setting(): void {
		// The AMC_COPILOT_DISABLED constant wins when defined; it is not defined in the test suite.
		$this->assertFalse( KillSwitch::is_locked_by_constant() );
		$this->assertFalse( KillSwitch::is_active() );

		Settings::update( [ 'kill_switch' => true ] );

		$this->assertTrue( KillSwitch::is_active() );
	}
}
