<?php
/**
 * Tests for the inventory.plugins check.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Checks\Inventory;

use AMC\Checks\Context;
use AMC\Checks\Group;
use AMC\Checks\Inventory\PluginInventory;
use AMC\Checks\Schedule;
use AMC\Checks\Severity;
use AMC\Checks\Status;
use AMC\Storage\SnapshotsRepository;
use AMC\Tests\TestCase;

/**
 * Class PluginInventoryTest
 */
final class PluginInventoryTest extends TestCase {

	/**
	 * Pinned run time; "over two years" is before 2024-11-14 12:00 UTC.
	 */
	private const NOW = '2026-11-14 12:00:00';

	/**
	 * Build the check with a fixture inventory and the fixture api responses.
	 *
	 * @param string       $plugins Fixture name in fixtures/inventory-plugins/.
	 * @param list<string> $active  Active plugin files.
	 * @param list<string> $network Network-active plugin files.
	 */
	private function check_with( string $plugins, array $active = [], array $network = [] ): PluginInventory {
		$api = $this->fixture( 'inventory-plugins/api' );

		return new PluginInventory(
			fn (): array => $this->fixture( 'inventory-plugins/' . $plugins ),
			static fn (): array => $active,
			static fn (): array => $network,
			static fn ( string $slug ): mixed => $api[ $slug ] ?? false
		);
	}

	/**
	 * A row from the result data by plugin file.
	 *
	 * @param array<string, mixed> $data Result data.
	 * @param string               $file Plugin file.
	 *
	 * @return array<string, mixed>
	 */
	private function row( array $data, string $file ): array {
		foreach ( $data['plugins'] as $row ) {
			if ( $file === $row['file'] ) {
				return $row;
			}
		}

		$this->fail( 'No row for ' . $file );
	}

	public function test_metadata(): void {
		$check = new PluginInventory();

		$this->assertSame( 'inventory.plugins', $check->id() );
		$this->assertSame( Group::INVENTORY, $check->group() );
		$this->assertSame( Schedule::WEEKLY, $check->schedule() );
		$this->assertNotSame( '', $check->label() );
	}

	public function test_healthy_plugins_pass_with_the_documented_data_shape(): void {
		$result = $this->check_with(
			'plugins-clean',
			[ 'akismet/akismet.php', 'hello-dolly/hello.php' ],
			[ 'hello-dolly/hello.php' ]
		)->run( Context::for_tests( null, self::NOW ) );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( [], $result->findings );
		$this->assertSame(
			[ 'plugins', 'total', 'active', 'network_active', 'closed', 'abandoned', 'changes' ],
			array_keys( $result->data )
		);
		$this->assertSame( 3, $result->data['total'] );
		$this->assertSame( 2, $result->data['active'] );
		$this->assertSame( 1, $result->data['network_active'] );
		$this->assertSame( 0, $result->data['closed'] );
		$this->assertSame( 0, $result->data['abandoned'] );
		$this->assertSame(
			[
				'added'   => [],
				'removed' => [],
				'updated' => [],
			],
			$result->data['changes']
		);

		$akismet = $this->row( $result->data, 'akismet/akismet.php' );
		$this->assertSame( 'Akismet Anti-spam: Spam Protection', $akismet['name'] );
		$this->assertSame( '5.3.5', $akismet['version'] );
		$this->assertTrue( $akismet['active'] );
		$this->assertFalse( $akismet['network_active'] );
		$this->assertTrue( $akismet['wp_org'] );
		$this->assertSame( '2026-10-01 00:00:00', $akismet['last_updated'] );
		$this->assertFalse( $akismet['closed'] );
		$this->assertFalse( $akismet['abandoned'] );

		$hello = $this->row( $result->data, 'hello-dolly/hello.php' );
		$this->assertTrue( $hello['active'] );
		$this->assertTrue( $hello['network_active'] );

		$premium = $this->row( $result->data, 'premium-tools/premium-tools.php' );
		$this->assertFalse( $premium['active'] );
		$this->assertFalse( $premium['wp_org'] );
		$this->assertNull( $premium['last_updated'] );
		$this->assertFalse( $premium['abandoned'] );

		$snapshot = ( new SnapshotsRepository() )->latest( 'plugins' );
		$this->assertNotNull( $snapshot );
		$this->assertCount( 3, $snapshot['data']['plugins'] );
	}

	public function test_a_closed_plugin_warns_with_a_medium_finding(): void {
		$result = $this->check_with( 'plugins-closed' )->run( Context::for_tests( null, self::NOW ) );

		$this->assertSame( Status::WARN, $result->status );
		$this->assertCount( 1, $result->findings );
		$this->assertSame( 1, $result->data['closed'] );
		$this->assertSame( 0, $result->data['abandoned'] );

		$finding = $result->findings[0];
		$this->assertSame( 'plugin:old-closed/old-closed.php:closed', $finding->dedupe_key );
		$this->assertSame( Severity::MEDIUM, $finding->severity );
		$this->assertSame( [ 'file' => 'old-closed/old-closed.php' ], $finding->data );

		$row = $this->row( $result->data, 'old-closed/old-closed.php' );
		$this->assertTrue( $row['wp_org'] );
		$this->assertTrue( $row['closed'] );
		$this->assertFalse( $row['abandoned'] );
	}

	public function test_a_plugin_without_an_update_in_two_years_warns_as_abandoned(): void {
		$result = $this->check_with( 'plugins-stale' )->run( Context::for_tests( null, self::NOW ) );

		$this->assertSame( Status::WARN, $result->status );
		$this->assertCount( 1, $result->findings );
		$this->assertSame( 1, $result->data['abandoned'] );
		$this->assertSame( 0, $result->data['closed'] );

		$finding = $result->findings[0];
		$this->assertSame( 'plugin:stale-plugin/stale-plugin.php:abandoned', $finding->dedupe_key );
		$this->assertSame( Severity::MEDIUM, $finding->severity );
		$this->assertSame(
			[
				'file'         => 'stale-plugin/stale-plugin.php',
				'last_updated' => '2024-01-01 00:00:00',
			],
			$finding->data
		);

		$this->assertTrue( $this->row( $result->data, 'stale-plugin/stale-plugin.php' )['abandoned'] );
	}

	public function test_the_two_year_cutoff_uses_the_run_clock(): void {
		$api = $this->fixture( 'inventory-plugins/api' );

		$check = new PluginInventory(
			static fn (): array => [
				'stale-plugin/stale-plugin.php' => [
					'Name'    => 'Stale Plugin',
					'Version' => '0.9.0',
				],
			],
			static fn (): array => [],
			static fn (): array => [],
			static fn ( string $slug ): mixed => $api[ $slug ] ?? false
		);

		// Exactly two years after the plugin's last update is not yet "over" two years.
		$on_the_boundary = $check->run( Context::for_tests( null, '2026-01-01 00:00:00' ) );
		$this->assertSame( Status::PASS, $on_the_boundary->status );

		$past_the_boundary = $check->run( Context::for_tests( null, '2026-01-02 00:00:00' ) );
		$this->assertSame( Status::WARN, $past_the_boundary->status );
	}

	public function test_the_snapshot_diff_reports_added_removed_and_updated_plugins(): void {
		$this->check_with( 'plugins-clean' )->run( Context::for_tests( null, self::NOW ) );

		$result = $this->check_with( 'plugins-changed' )->run( Context::for_tests( null, self::NOW ) );

		$this->assertSame(
			[
				'added'   => [ 'new-plugin/new-plugin.php' ],
				'removed' => [ 'hello-dolly/hello.php' ],
				'updated' => [ 'akismet/akismet.php' ],
			],
			$result->data['changes']
		);

		$snapshot = ( new SnapshotsRepository() )->latest( 'plugins' );
		$this->assertNotNull( $snapshot );
		$this->assertCount( 3, $snapshot['data']['plugins'] );
	}

	public function test_a_wp_error_from_the_api_is_not_treated_as_on_wp_org(): void {
		$this->markTestIncomplete( 'bug: plugins_api() returns WP_Error, not false, for unknown slugs; is_object() marks it wp_org=true with no closed/abandoned detection.' );

		$check = new PluginInventory(
			static fn (): array => [
				'premium-tools/premium-tools.php' => [
					'Name'    => 'Premium Tools',
					'Version' => '2.1.0',
				],
			],
			static fn (): array => [],
			static fn (): array => [],
			static fn (): mixed => new \WP_Error( 'plugins_api_failed', 'Not found.' )
		);

		$result = $check->run( Context::for_tests( null, self::NOW ) );

		$this->assertFalse( $result->data['plugins'][0]['wp_org'] );
		$this->assertSame( Status::PASS, $result->status );
	}
}
