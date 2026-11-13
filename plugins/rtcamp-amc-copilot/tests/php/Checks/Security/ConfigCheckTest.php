<?php
/**
 * Tests for the security.config check.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Checks\Security;

use AMC\Checks\Context;
use AMC\Checks\Group;
use AMC\Checks\Schedule;
use AMC\Checks\Security\ConfigCheck;
use AMC\Checks\Severity;
use AMC\Checks\Status;
use AMC\Tests\TestCase;

/**
 * Class ConfigCheckTest
 */
final class ConfigCheckTest extends TestCase {

	/**
	 * Facts of a hardened site, with overrides.
	 *
	 * @param array<string, mixed> $override Overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function facts( array $override = [] ): array {
		$salts = [];
		foreach ( [ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ] as $i => $name ) {
			$salts[ $name ] = str_repeat( chr( 65 + $i ), 64 );
		}

		return array_replace(
			[
				'disallow_file_edit'    => true,
				'wp_debug'              => false,
				'wp_debug_display'      => false,
				'salts'                 => $salts,
				'admin_username_exists' => false,
				'xmlrpc_enabled'        => false,
				'uploads_listing'       => false,
			],
			$override
		);
	}

	/**
	 * Run the check on facts.
	 *
	 * @param array<string, mixed> $facts Facts.
	 */
	private function run_with( array $facts ): \AMC\Checks\Result {
		return ( new ConfigCheck( static fn (): array => $facts ) )->run( Context::for_tests() );
	}

	public function test_metadata(): void {
		$check = new ConfigCheck();

		$this->assertSame( 'security.config', $check->id() );
		$this->assertSame( Group::SECURITY, $check->group() );
		$this->assertSame( Schedule::DAILY, $check->schedule() );
		$this->assertNotSame( '', $check->label() );
	}

	public function test_a_hardened_site_passes_without_findings(): void {
		$result = $this->run_with( $this->facts() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( [], $result->findings );
		$this->assertSame( 13, $result->data['counts']['pass'] );
		$this->assertCount( 13, $result->data['items'] );
	}

	public function test_failures_and_warnings_become_digest_severity_findings(): void {
		$result = $this->run_with(
			$this->facts(
				[
					'disallow_file_edit'    => false,
					'admin_username_exists' => true,
					'wp_debug'              => true,
					'wp_debug_display'      => null,
				]
			)
		);

		$this->assertSame( Status::FAIL, $result->status );

		$by_key = [];
		foreach ( $result->findings as $finding ) {
			$by_key[ $finding->dedupe_key ] = $finding;
		}

		$this->assertSame( [ 'config:disallow_file_edit', 'config:wp_debug_display', 'config:admin_username_exists' ], array_keys( $by_key ) );
		$this->assertSame( Severity::MEDIUM, $by_key['config:disallow_file_edit']->severity );
		$this->assertSame( Severity::LOW, $by_key['config:admin_username_exists']->severity );
		$this->assertStringContainsString( 'DISALLOW_FILE_EDIT', $by_key['config:disallow_file_edit']->data['detail'] );
	}

	public function test_only_warnings_warn(): void {
		$this->assertSame( Status::WARN, $this->run_with( $this->facts( [ 'xmlrpc_enabled' => true ] ) )->status );
	}

	public function test_salt_problems_are_translated_by_code_and_never_expose_the_secret(): void {
		$facts                       = $this->facts();
		$facts['salts']['AUTH_KEY']  = 'put your unique phrase here';
		$facts['salts']['NONCE_KEY'] = 'tooshort-secret-value';

		$result = $this->run_with( $facts );
		$items  = array_column( $result->data['items'], null, 'key' );

		$this->assertSame( 'placeholder', $items['salt_auth_key']['code'] );
		$this->assertStringContainsString( 'default placeholder', $items['salt_auth_key']['detail'] );
		$this->assertSame( 'short', $items['salt_nonce_key']['code'] );
		$this->assertStringNotContainsString( 'tooshort-secret-value', (string) wp_json_encode( $result->to_array() ) );
	}

	public function test_default_facts_read_the_real_site(): void {
		$facts = ConfigCheck::gather_facts();

		$this->assertArrayHasKey( 'salts', $facts );
		$this->assertCount( 8, $facts['salts'] );
		$this->assertIsBool( $facts['admin_username_exists'] );
		$this->assertIsBool( $facts['xmlrpc_enabled'] );
		$this->assertFalse( $facts['uploads_listing'], 'Unreachable test site counts as off' );
	}

	public function test_admin_username_fact_follows_the_users_table(): void {
		self::factory()->user->create( [ 'user_login' => 'admin' ] );

		$this->assertTrue( ConfigCheck::gather_facts()['admin_username_exists'] );
	}
}
