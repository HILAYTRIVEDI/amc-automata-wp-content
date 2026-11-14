<?php
/**
 * Tests for the deploy diff wiring in the inventory.plugins check.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Checks\Inventory;

use AMC\Capture\ChangeLog;
use AMC\Checks\Context;
use AMC\Checks\Inventory\PluginInventory;
use AMC\Storage\ChangesRepository;
use AMC\Tests\TestCase;

/**
 * Class PluginInventoryDeployDiffTest
 */
final class PluginInventoryDeployDiffTest extends TestCase {

	/**
	 * Run the check once against a fake set of installed plugins.
	 *
	 * @param array<string, string> $installed Plugin file => version.
	 *
	 * @return array<string, mixed> Result data.
	 */
	private function run_inventory( array $installed ): array {
		$plugins = [];
		foreach ( $installed as $file => $version ) {
			$plugins[ $file ] = [
				'Name'    => ucfirst( dirname( $file ) ),
				'Version' => $version,
			];
		}

		$check = new PluginInventory(
			static fn (): array => $plugins,
			static fn (): array => [],
			static fn (): array => [],
			static fn (): bool => false
		);

		return $check->run( Context::for_tests() )->data;
	}

	/**
	 * Plugin version change rows, oldest first. Activation rows are left out:
	 * a test environment that activates this plugin from the CLI leaves one
	 * behind, and it is not what these tests are about.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function logged(): array {
		$items = ( new ChangesRepository() )->query( [ 'type' => 'plugin' ] )['items'];

		return array_values( array_reverse( array_filter( $items, static fn ( array $row ): bool => 'activate' !== $row['action'] ) ) );
	}

	/**
	 * Compact view of the rows: "action:item:old>new:source".
	 *
	 * @return list<string>
	 */
	private function summary(): array {
		return array_map(
			static fn ( array $row ): string => sprintf( '%s:%s:%s>%s:%s', $row['action'], $row['item'], $row['old_version'], $row['new_version'], $row['source'] ),
			$this->logged()
		);
	}

	public function test_first_run_without_a_previous_snapshot_logs_nothing(): void {
		$data = $this->run_inventory( [ 'akismet/akismet.php' => '5.0' ] );

		$this->assertSame( [], $this->logged() );
		$this->assertSame(
			[
				'added'   => [],
				'removed' => [],
				'updated' => [],
			],
			$data['changes']
		);
	}

	public function test_a_deployed_version_change_is_logged_once_with_source_deploy(): void {
		$this->run_inventory(
			[
				'akismet/akismet.php' => '5.0',
				'same/same.php'       => '1.0',
			]
		);

		$data = $this->run_inventory(
			[
				'akismet/akismet.php' => '5.1',
				'same/same.php'       => '1.0',
			]
		);

		$this->assertSame( [ 'akismet/akismet.php' ], $data['changes']['updated'] );
		$this->assertSame( [ 'update:akismet:5.0>5.1:deploy' ], $this->summary() );
		$this->assertSame( 'Akismet', $this->logged()[0]['item_name'] );
		$this->assertSame( 0, $this->logged()[0]['user_id'] );

		// A further run with nothing new in the code logs nothing more.
		$this->run_inventory(
			[
				'akismet/akismet.php' => '5.1',
				'same/same.php'       => '1.0',
			]
		);
		$this->assertCount( 1, $this->logged() );
	}

	public function test_an_update_logged_by_the_upgrader_hooks_is_not_logged_again_as_deploy(): void {
		$file = 'akismet/akismet.php';
		$this->run_inventory( [ $file => '5.0' ] );

		// wp-admin updates the plugin: ChangeLog sees the version before and after.
		$on_disk = [ $file => '5.0' ];
		$log     = new ChangeLog(
			static function ( string $plugin_file ) use ( &$on_disk ): array {
				return [
					'name'    => 'Akismet',
					'version' => $on_disk[ $plugin_file ],
				];
			},
			null,
			null,
			null,
			false
		);
		$extra   = [
			'type'   => 'plugin',
			'action' => 'update',
			'plugin' => $file,
		];
		$log->on_pre_install( true, $extra );
		$on_disk[ $file ] = '5.1';
		$log->on_upgrade_complete( new \stdClass(), $extra );

		$this->assertSame( [ 'update:akismet:5.0>5.1:admin' ], $this->summary() );

		// The next inventory run sees 5.0 -> 5.1 but must not repeat it.
		$data = $this->run_inventory( [ $file => '5.1' ] );

		$this->assertSame( [ $file ], $data['changes']['updated'] );
		$this->assertSame( [ 'update:akismet:5.0>5.1:admin' ], $this->summary() );
	}

	public function test_a_wp_cli_update_is_not_logged_again_as_deploy(): void {
		$this->run_inventory( [ 'akismet/akismet.php' => '5.0' ] );

		( new ChangesRepository() )->insert(
			[
				'type'        => 'plugin',
				'action'      => 'update',
				'item'        => 'akismet',
				'old_version' => '5.0',
				'new_version' => '5.1',
				'source'      => 'cli',
			]
		);

		$this->run_inventory( [ 'akismet/akismet.php' => '5.1' ] );

		$this->assertSame( [ 'update:akismet:5.0>5.1:cli' ], $this->summary() );
	}

	public function test_a_hook_logged_change_does_not_hide_a_deploy_of_another_plugin(): void {
		$this->run_inventory(
			[
				'hooked/hooked.php'     => '1.0',
				'deployed/deployed.php' => '1.0',
			]
		);

		( new ChangesRepository() )->insert(
			[
				'type'        => 'plugin',
				'action'      => 'update',
				'item'        => 'hooked',
				'old_version' => '1.0',
				'new_version' => '1.1',
				'source'      => 'admin',
			]
		);

		$this->run_inventory(
			[
				'hooked/hooked.php'     => '1.1',
				'deployed/deployed.php' => '1.1',
			]
		);

		$this->assertSame( [ 'update:hooked:1.0>1.1:admin', 'update:deployed:1.0>1.1:deploy' ], $this->summary() );
	}

	public function test_added_and_removed_plugins_are_logged_as_install_and_delete(): void {
		$this->run_inventory(
			[
				'gone/gone.php' => '2.0',
				'kept/kept.php' => '1.0',
			]
		);

		$data = $this->run_inventory(
			[
				'kept/kept.php' => '1.0',
				'new/new.php'   => '0.1',
			]
		);

		$this->assertSame( [ 'new/new.php' ], $data['changes']['added'] );
		$this->assertSame( [ 'gone/gone.php' ], $data['changes']['removed'] );

		$summary = $this->summary();
		sort( $summary );
		$this->assertSame( [ 'delete:gone:2.0>:deploy', 'install:new:>0.1:deploy' ], $summary );
	}
}
