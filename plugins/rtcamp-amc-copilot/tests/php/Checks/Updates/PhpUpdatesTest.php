<?php
/**
 * Tests for the updates.php check.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Checks\Updates;

use AMC\Checks\Context;
use AMC\Checks\Group;
use AMC\Checks\Schedule;
use AMC\Checks\Severity;
use AMC\Checks\Status;
use AMC\Checks\Updates\PhpUpdates;
use AMC\Tests\TestCase;

/**
 * Class PhpUpdatesTest
 */
final class PhpUpdatesTest extends TestCase {

	/**
	 * Build the check with fixtures.
	 *
	 * @param string $fixture Fixture name in fixtures/updates-php/.
	 * @param string $version Installed PHP version.
	 */
	private function check_with( string $fixture, string $version = '8.2.29' ): PhpUpdates {
		return new PhpUpdates(
			static fn (): string => $version,
			fn (): mixed => $this->fixture( 'updates-php/' . $fixture ),
			static fn (): array => [
				'foo/foo.php' => [
					'Name'        => 'Foo',
					'Version'     => '1.0',
					'RequiresPHP' => '8.3',
				],
				'bar/bar.php' => [
					'Name'        => 'Bar',
					'Version'     => '2.0',
					'RequiresPHP' => '7.4',
				],
			],
			static fn (): array => [ 'foo/foo.php' ]
		);
	}

	public function test_metadata(): void {
		$check = new PhpUpdates();

		$this->assertSame( 'updates.php', $check->id() );
		$this->assertSame( Group::UPDATES, $check->group() );
		$this->assertSame( Schedule::DAILY, $check->schedule() );
		$this->assertNotSame( '', $check->label() );
	}

	public function test_no_data_is_info_without_findings(): void {
		$check = new PhpUpdates(
			static fn (): string => '8.2.29',
			static fn (): mixed => null,
			static fn (): array => [],
			static fn (): array => []
		);

		$result = $check->run( Context::for_tests() );

		$this->assertSame( Status::INFO, $result->status );
		$this->assertSame( [], $result->findings );
		$this->assertSame( '8.2.29', $result->data['installed'] );
		$this->assertSame( [], $result->data['blocking_plugins'] );
	}

	public function test_default_source_works_without_wp_admin_includes(): void {
		// Cron does not load wp-admin/includes/misc.php; the check must load it
		// itself rather than report "no data". The cached Serve Happy answer
		// avoids a network call.
		set_site_transient(
			'php_check_' . md5( PHP_VERSION ),
			[
				'recommended_version'          => '8.3',
				'minimum_version'              => '7.4',
				'is_supported'                 => true,
				'is_secure'                    => true,
				'is_acceptable'                => true,
				'is_lower_than_future_minimum' => false,
			],
			HOUR_IN_SECONDS
		);

		$result = ( new PhpUpdates( null, null, static fn (): array => [], static fn (): array => [] ) )->run( Context::for_tests() );

		delete_site_transient( 'php_check_' . md5( PHP_VERSION ) );

		$this->assertTrue( function_exists( 'wp_check_php_version' ) );
		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( '8.3', $result->data['recommended'] );
	}

	public function test_acceptable_passes(): void {
		$result = $this->check_with( 'acceptable', '8.3.0' )->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( [], $result->findings );
		$this->assertTrue( $result->data['is_acceptable'] );
		$this->assertSame( '8.2', $result->data['recommended'] );
	}

	public function test_unacceptable_warns_with_finding(): void {
		$result = $this->check_with( 'unacceptable' )->run( Context::for_tests() );

		$this->assertSame( Status::WARN, $result->status );
		$this->assertCount( 2, $result->findings );

		$version_finding = null;
		foreach ( $result->findings as $f ) {
			if ( 'php:8.2.29' === $f->dedupe_key ) {
				$version_finding = $f;
			}
		}
		$this->assertNotNull( $version_finding );
		$this->assertSame( Severity::MEDIUM, $version_finding->severity );
	}

	public function test_blocking_plugin_raises_high_finding(): void {
		$result = $this->check_with( 'acceptable' )->run( Context::for_tests() );

		$this->assertSame( Status::WARN, $result->status );
		$this->assertSame( 1, count( $result->data['blocking_plugins'] ) );
		$this->assertSame( 'foo/foo.php', $result->data['blocking_plugins'][0]['file'] );

		$finding = null;
		foreach ( $result->findings as $f ) {
			if ( str_starts_with( $f->dedupe_key, 'php-plugin:' ) ) {
				$finding = $f;
			}
		}

		$this->assertNotNull( $finding );
		$this->assertSame( Severity::HIGH, $finding->severity );
		$this->assertSame( 'php-plugin:foo/foo.php', $finding->dedupe_key );
	}
}
