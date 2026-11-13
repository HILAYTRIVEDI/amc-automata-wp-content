<?php
/**
 * Tests for the deploy diff helper.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Capture;

use AMC\Capture\DeployDiff;
use AMC\Storage\ChangesRepository;
use AMC\Tests\TestCase;

/**
 * Class DeployDiffTest
 */
final class DeployDiffTest extends TestCase {

	/**
	 * Rows of one type, oldest first.
	 *
	 * @param string $type Type.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows( string $type ): array {
		return array_reverse( ( new ChangesRepository() )->query( [ 'type' => $type ] )['items'] );
	}

	public function test_no_previous_snapshot_means_no_baseline_and_no_rows(): void {
		$this->assertSame( 0, DeployDiff::record( 'plugin', null, [ 'a/a.php' => '1.0' ] ) );
		$this->assertSame( [], $this->rows( 'plugin' ) );
	}

	public function test_install_update_and_delete_are_written_with_source_deploy(): void {
		$written = DeployDiff::record(
			'plugin',
			[
				'akismet/akismet.php' => [
					'name'    => 'Akismet',
					'version' => '5.0',
				],
				'same/same.php'       => '1.0',
				'gone/gone.php'       => [
					'Name'    => 'Gone',
					'Version' => '2.0',
				],
			],
			[
				'akismet/akismet.php' => [
					'name'    => 'Akismet',
					'version' => '5.1',
				],
				'same/same.php'       => '1.0',
				'new/new.php'         => [
					'name'    => 'Brand New',
					'version' => '0.1',
				],
			]
		);

		$this->assertSame( 3, $written );
		$by_item = array_column( $this->rows( 'plugin' ), null, 'item' );

		$this->assertSame( [ 'update', '5.0', '5.1', 'deploy', 0, 'Akismet' ], [ $by_item['akismet']['action'], $by_item['akismet']['old_version'], $by_item['akismet']['new_version'], $by_item['akismet']['source'], $by_item['akismet']['user_id'], $by_item['akismet']['item_name'] ] );
		$this->assertSame( [ 'install', '', '0.1', 'Brand New' ], [ $by_item['new']['action'], $by_item['new']['old_version'], $by_item['new']['new_version'], $by_item['new']['item_name'] ] );
		$this->assertSame( [ 'delete', '2.0', '', 'Gone' ], [ $by_item['gone']['action'], $by_item['gone']['old_version'], $by_item['gone']['new_version'], $by_item['gone']['item_name'] ] );
		$this->assertArrayNotHasKey( 'same', $by_item );
	}

	public function test_a_change_that_a_hook_already_logged_is_not_repeated(): void {
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

		$written = DeployDiff::record(
			'plugin',
			[
				'hooked/hooked.php'     => '1.0',
				'deployed/deployed.php' => '1.0',
			],
			[
				'hooked/hooked.php'     => '1.1',
				'deployed/deployed.php' => '1.1',
			]
		);

		$this->assertSame( 1, $written );
		$this->assertSame( [ 'hooked', 'deployed' ], array_column( $this->rows( 'plugin' ), 'item' ) );
		$this->assertSame( [ 'admin', 'deploy' ], array_column( $this->rows( 'plugin' ), 'source' ) );
	}

	public function test_calling_twice_does_not_duplicate_rows(): void {
		$previous = [ 'a/a.php' => '1.0' ];
		$current  = [ 'a/a.php' => '1.1' ];

		$this->assertSame( 1, DeployDiff::record( 'plugin', $previous, $current ) );
		$this->assertSame( 0, DeployDiff::record( 'plugin', $previous, $current ) );
	}

	public function test_a_hook_row_older_than_since_does_not_hide_a_new_deploy(): void {
		( new ChangesRepository() )->insert(
			[
				'type'        => 'plugin',
				'action'      => 'update',
				'item'        => 'a',
				'new_version' => '1.1',
				'source'      => 'admin',
				'created_at'  => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ),
			]
		);

		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$this->assertSame( 1, DeployDiff::record( 'plugin', [ 'a/a.php' => '1.0' ], [ 'a/a.php' => '1.1' ], $since ) );
	}

	public function test_themes_and_core_use_their_own_keys(): void {
		$this->assertSame( 1, DeployDiff::record( 'theme', [ 'twentytest' => '1.0' ], [ 'twentytest' => '1.2' ] ) );
		$this->assertSame( 1, DeployDiff::record( 'core', [ 'wordpress' => '6.9.0' ], [ 'wordpress' => '6.9.1' ] ) );

		$theme = $this->rows( 'theme' )[0];
		$core  = $this->rows( 'core' )[0];
		$this->assertSame( [ 'twentytest', 'update', 'deploy' ], [ $theme['item'], $theme['action'], $theme['source'] ] );
		$this->assertSame( [ 'wordpress', '6.9.0', '6.9.1' ], [ $core['item'], $core['old_version'], $core['new_version'] ] );
	}

	public function test_unknown_type_writes_nothing(): void {
		$this->assertSame( 0, DeployDiff::record( 'user', [ 'a' => '1' ], [ 'a' => '2' ] ) );
	}

	public function test_empty_previous_snapshot_is_a_baseline_of_nothing(): void {
		$this->assertSame( 1, DeployDiff::record( 'plugin', [], [ 'first/first.php' => '1.0' ] ) );
	}
}
