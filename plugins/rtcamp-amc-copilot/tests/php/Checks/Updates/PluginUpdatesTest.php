<?php
/**
 * Tests for the updates.plugins check.
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
use AMC\Checks\Updates\PluginUpdates;
use AMC\Tests\TestCase;

/**
 * Class PluginUpdatesTest
 */
final class PluginUpdatesTest extends TestCase {

	/**
	 * Build the check with fixtures and fake sources.
	 *
	 * @param string|null   $fixture Fixture name in fixtures/updates-plugins/.
	 * @param callable|null $api     plugins_api() replacement.
	 */
	private function check_with( string $fixture, ?callable $api = null ): PluginUpdates {
		[ $transient, $plugins ] = $this->fixture( 'updates-plugins/' . $fixture );
		$calls                   = [];

		return new PluginUpdates(
			static fn () => $transient,
			static fn (): array => $plugins,
			static fn (): array => [ 'akismet/akismet.php' ],
			static fn (): array => [],
			function ( string $slug ) use ( &$calls, $api ): mixed {
				$calls[] = $slug;

				return null !== $api ? $api( $slug ) : $this->fixture( 'updates-plugins/changelog' );
			}
		);
	}

	public function test_metadata(): void {
		$check = new PluginUpdates();

		$this->assertSame( 'updates.plugins', $check->id() );
		$this->assertSame( Group::UPDATES, $check->group() );
		$this->assertSame( Schedule::TWICE_DAILY, $check->schedule() );
		$this->assertNotSame( '', $check->label() );
	}

	public function test_no_update_data_is_info_without_findings(): void {
		$result = $this->check_with( 'none' )->run( Context::for_tests() );

		$this->assertSame( Status::INFO, $result->status );
		$this->assertSame( [], $result->findings );
		$this->assertSame( 4, $result->data['total'] );
	}

	public function test_up_to_date_passes_without_findings(): void {
		$result = $this->check_with( 'up-to-date' )->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( [], $result->findings );
		$this->assertSame( 0, $result->data['pending'] );
		$this->assertSame( gmdate( 'c', 1793520000 ), $result->data['last_checked'] );
	}

	public function test_pending_update_warns_with_finding_and_details(): void {
		$result = $this->check_with( 'pending' )->run( Context::for_tests() );

		$this->assertSame( Status::WARN, $result->status );
		$this->assertCount( 1, $result->findings );
		$this->assertSame( 1, $result->data['pending'] );

		$finding = $result->findings[0];
		$this->assertSame( 'plugin:akismet/akismet.php:5.3.6', $finding->dedupe_key );
		$this->assertSame( Severity::MEDIUM, $finding->severity );

		$akismet = null;
		foreach ( $result->data['plugins'] as $row ) {
			if ( 'akismet/akismet.php' === $row['file'] ) {
				$akismet = $row;
			}
		}

		$this->assertNotNull( $akismet );
		$this->assertTrue( $akismet['active'] );
		$this->assertTrue( $akismet['update_available'] );
		$this->assertSame( '5.3.6', $akismet['new_version'] );
		$this->assertSame( '6.9', $akismet['tested_up_to'] );
		$this->assertSame( '7.2', $akismet['requires_php'] );
		$this->assertStringContainsString( 'Fixed a spam-check edge case.', (string) $akismet['changelog_excerpt'] );
	}

	public function test_plugin_without_update_source_is_reported(): void {
		$result = $this->check_with( 'up-to-date' )->run( Context::for_tests() );

		$this->assertSame( [ 'old-closed/old-closed.php', 'premium-tools/premium-tools.php' ], $result->data['no_update_source'] );

		foreach ( $result->data['plugins'] as $row ) {
			if ( 'premium-tools/premium-tools.php' === $row['file'] ) {
				$this->assertNull( $row['update_source'] );
			}
		}
	}

	public function test_changelog_fetch_is_capped_per_run_and_rotates(): void {
		$calls = [];
		$check = $this->check_with(
			'pending',
			static function ( string $slug ) use ( &$calls ): mixed {
				$calls[] = $slug;

				return false; // API down for this slug.
			}
		);

		$first = $check->run( Context::for_tests() );
		$this->assertCount( 2, $calls ); // The two wp.org plugins in the response.
		$this->assertNull( $first->data['plugins'][0]['changelog_excerpt'] );

		// Next run starts again (cursor wrapped): still capped, no explosion.
		$second = $check->run( Context::for_tests( $first->data ) );
		$this->assertCount( 4, $calls );
		$this->assertSame( 0, $second->data['cursor'] );
	}

	public function test_no_plugins_installed_is_info(): void {
		$check = new PluginUpdates(
			static fn () => (object) [
				'response'  => [],
				'no_update' => [],
			],
			static fn (): array => [],
			static fn (): array => [],
			static fn (): array => [],
			static fn (): mixed => false
		);

		$result = $check->run( Context::for_tests() );

		$this->assertSame( Status::INFO, $result->status );
	}
}
