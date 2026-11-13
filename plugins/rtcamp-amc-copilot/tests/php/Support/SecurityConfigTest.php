<?php
/**
 * Tests for the SecurityConfig evaluator.
 *
 * @package AMC\Support
 */

declare( strict_types = 1 );

namespace AMC\Tests\Support;

use AMC\Support\SecurityConfig;
use PHPUnit\Framework\TestCase;

/**
 * Class SecurityConfigTest
 */
final class SecurityConfigTest extends TestCase {

	/**
	 * Real-looking salt values, well over the 32-character minimum.
	 *
	 * @return array<string, string>
	 */
	private function good_salts(): array {
		$names = [ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ];

		$salts = [];
		foreach ( $names as $index => $name ) {
			$salts[ $name ] = str_repeat( chr( 97 + $index ), 48 );
		}

		return $salts;
	}

	/**
	 * A fully hardened set of facts: every check passes.
	 */
	public function test_a_hardened_site_passes_everything(): void {
		$results = SecurityConfig::evaluate(
			[
				'disallow_file_edit'    => true,
				'wp_debug_display'      => false,
				'salts'                 => $this->good_salts(),
				'admin_username_exists' => false,
				'xmlrpc_enabled'        => false,
				'uploads_listing'       => false,
			]
		);

		$this->assertSame( [], $this->statuses_of( $results, 'fail' ) );
		$this->assertSame( [], $this->statuses_of( $results, 'warn' ) );
		$this->assertCount( 13, $results );
	}

	/**
	 * The result shape: every item carries exactly the four keys.
	 */
	public function test_every_item_has_the_documented_shape(): void {
		$results = SecurityConfig::evaluate( [ 'salts' => $this->good_salts() ] );

		foreach ( $results as $result ) {
			$this->assertSame( [ 'key', 'status', 'title', 'detail' ], array_keys( $result ) );
			$this->assertContains( $result['status'], [ 'pass', 'warn', 'fail' ] );
			$this->assertNotSame( '', $result['title'] );
			$this->assertNotSame( '', $result['detail'] );
		}
	}

	/**
	 * All eight salt keys are present, one check each.
	 */
	public function test_all_eight_salts_are_checked(): void {
		$results  = SecurityConfig::evaluate( [ 'salts' => $this->good_salts() ] );
		$expected = [ 'salt_auth_key', 'salt_secure_auth_key', 'salt_logged_in_key', 'salt_nonce_key', 'salt_auth_salt', 'salt_secure_auth_salt', 'salt_logged_in_salt', 'salt_nonce_salt' ];

		$pass_keys = $this->statuses_of( $results, 'pass' );

		$this->assertSame(
			$expected,
			array_values(
				array_filter(
					$pass_keys,
					static function ( string $key ): bool {
						return str_starts_with( $key, 'salt_' );
					}
				)
			)
		);
	}

	/**
	 * File editing enabled fails.
	 */
	public function test_disallow_file_edit_false_fails(): void {
		$this->assertContains( 'disallow_file_edit', $this->statuses_of( SecurityConfig::evaluate( [] ), 'fail' ) );
	}

	/**
	 * Debug display on fails; off and undefined pass.
	 *
	 * @dataProvider provide_debug_display_facts
	 *
	 * @param mixed  $value    The wp_debug_display fact.
	 * @param string $expected Expected status.
	 */
	public function test_wp_debug_display( $value, string $expected ): void {
		$results = SecurityConfig::evaluate(
			[
				'salts'            => $this->good_salts(),
				'wp_debug_display' => $value,
			]
		);

		$this->assertContains( 'wp_debug_display', $this->statuses_of( $results, $expected ) );
	}

	/**
	 * Debug display fact variants.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function provide_debug_display_facts(): array {
		return [
			'display on'        => [ true, 'fail' ],
			'display on string' => [ 'true', 'fail' ],
			'display off'       => [ false, 'pass' ],
			'not defined'       => [ null, 'pass' ],
		];
	}

	/**
	 * A salt still holding the default placeholder fails.
	 */
	public function test_default_placeholder_salt_fails(): void {
		$salts             = $this->good_salts();
		$salts['AUTH_KEY'] = 'put your unique phrase here';

		$this->assertContains( 'salt_auth_key', $this->statuses_of( SecurityConfig::evaluate( [ 'salts' => $salts ] ), 'fail' ) );
	}

	/**
	 * A too-short salt fails.
	 */
	public function test_short_salt_fails(): void {
		$salts               = $this->good_salts();
		$salts['NONCE_SALT'] = 'abc';

		$this->assertContains( 'salt_nonce_salt', $this->statuses_of( SecurityConfig::evaluate( [ 'salts' => $salts ] ), 'fail' ) );
	}

	/**
	 * A missing salt constant fails.
	 */
	public function test_missing_salt_fails(): void {
		$salts = $this->good_salts();
		unset( $salts['SECURE_AUTH_SALT'] );

		$this->assertContains( 'salt_secure_auth_salt', $this->statuses_of( SecurityConfig::evaluate( [ 'salts' => $salts ] ), 'fail' ) );
	}

	/**
	 * An entirely missing salts array fails every salt check.
	 */
	public function test_missing_salts_array_fails_all_eight(): void {
		$results = SecurityConfig::evaluate( [] );
		$fails   = $this->statuses_of( $results, 'fail' );

		$this->assertCount(
			8,
			array_filter(
				$fails,
				static function ( string $key ): bool {
					return str_starts_with( $key, 'salt_' );
				}
			)
		);
	}

	/**
	 * The `admin` username, XML-RPC and uploads listing produce their verdicts.
	 */
	public function test_risky_facts_warn_or_fail(): void {
		$results = SecurityConfig::evaluate(
			[
				'salts'                 => $this->good_salts(),
				'admin_username_exists' => true,
				'xmlrpc_enabled'        => true,
				'uploads_listing'       => true,
			]
		);

		$this->assertContains( 'admin_username_exists', $this->statuses_of( $results, 'warn' ) );
		$this->assertContains( 'xmlrpc_enabled', $this->statuses_of( $results, 'warn' ) );
		$this->assertContains( 'uploads_listing', $this->statuses_of( $results, 'fail' ) );
	}

	/**
	 * String-shaped facts ("true"/"false") are understood like booleans.
	 */
	public function test_string_facts_are_understood(): void {
		$results = SecurityConfig::evaluate(
			[
				'disallow_file_edit'    => 'true',
				'admin_username_exists' => 'false',
				'xmlrpc_enabled'        => 'false',
				'uploads_listing'       => 'false',
				'salts'                 => $this->good_salts(),
			]
		);

		$this->assertSame( [], $this->statuses_of( $results, 'fail' ) );
	}

	/**
	 * The keys of checks in a given status.
	 *
	 * @param array<int, array<string, mixed>> $results Evaluator results.
	 * @param string                           $status  Status to filter on.
	 *
	 * @return string[]
	 */
	private function statuses_of( array $results, string $status ): array {
		$keys = [];

		foreach ( $results as $result ) {
			if ( $status === $result['status'] ) {
				$keys[] = $result['key'];
			}
		}

		return $keys;
	}
}
