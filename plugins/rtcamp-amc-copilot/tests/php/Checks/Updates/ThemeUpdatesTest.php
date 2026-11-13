<?php
/**
 * Tests for the updates.themes check.
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
use AMC\Checks\Updates\ThemeUpdates;
use AMC\Tests\TestCase;

/**
 * Class ThemeUpdatesTest
 */
final class ThemeUpdatesTest extends TestCase {

	/**
	 * Build the check with fixtures and fake sources.
	 *
	 * @param string $transient_fixture Transient fixture name.
	 * @param string $themes_fixture    Theme inventory fixture name.
	 */
	private function check_with( string $transient_fixture, string $themes_fixture = 'themes' ): ThemeUpdates {
		return new ThemeUpdates(
			fn () => $this->fixture( 'updates-themes/' . $transient_fixture ),
			fn (): array => $this->fixture( 'updates-themes/' . $themes_fixture ),
			static fn (): array => [
				'stylesheet' => 'twentytwentytwenty',
				'template'   => 'twentytwentytwenty',
			],
			fn (): mixed => $this->fixture( 'updates-themes/changelog' )
		);
	}

	public function test_metadata(): void {
		$check = new ThemeUpdates();

		$this->assertSame( 'updates.themes', $check->id() );
		$this->assertSame( Group::UPDATES, $check->group() );
		$this->assertSame( Schedule::TWICE_DAILY, $check->schedule() );
		$this->assertNotSame( '', $check->label() );
	}

	public function test_no_update_data_is_info_without_findings(): void {
		$result = $this->check_with( 'transient-none' )->run( Context::for_tests() );

		$this->assertSame( Status::INFO, $result->status );
		$this->assertSame( [], $result->findings );
		$this->assertSame( 2, $result->data['total'] );
		$this->assertSame( 'twentytwentytwenty', $result->data['active'] );
	}

	public function test_up_to_date_passes_without_findings(): void {
		$result = $this->check_with( 'transient-up-to-date' )->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( [], $result->findings );
		$this->assertSame( 0, $result->data['pending'] );
		$this->assertSame( gmdate( 'c', 1793520000 ), $result->data['last_checked'] );
	}

	public function test_pending_update_warns_with_finding_and_changelog(): void {
		$result = $this->check_with( 'transient-pending' )->run( Context::for_tests() );

		$this->assertSame( Status::WARN, $result->status );
		$this->assertCount( 1, $result->findings );
		$this->assertSame( 1, $result->data['pending'] );

		$finding = $result->findings[0];
		$this->assertSame( 'theme:twentytwentytwenty:1.0.3', $finding->dedupe_key );
		$this->assertSame( Severity::MEDIUM, $finding->severity );
		$this->assertTrue( $finding->data['active'] );

		$theme = null;
		foreach ( $result->data['themes'] as $row ) {
			if ( 'twentytwentytwenty' === $row['stylesheet'] ) {
				$theme = $row;
			}
		}

		$this->assertNotNull( $theme );
		$this->assertTrue( $theme['active'] );
		$this->assertTrue( $theme['update_available'] );
		$this->assertSame( '1.0.3', $theme['new_version'] );
		$this->assertSame( 'wp.org', $theme['update_source'] );
		$this->assertStringContainsString( 'Block pattern tweaks.', (string) $theme['changelog_excerpt'] );

		// Inactive theme has no update.
		foreach ( $result->data['themes'] as $row ) {
			if ( 'storefront' === $row['stylesheet'] ) {
				$this->assertFalse( $row['update_available'] );
			}
		}
	}
}
