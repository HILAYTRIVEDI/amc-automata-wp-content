<?php
/**
 * Tests for the change log listeners.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Capture;

use AMC\Capture\ChangeLog;
use AMC\Core\Settings;
use AMC\Storage\ChangesRepository;
use AMC\Tests\TestCase;

/**
 * Class ChangeLogTest
 */
final class ChangeLogTest extends TestCase {

	/**
	 * Versions the fake plugin reader returns now.
	 *
	 * @var array<string, array{name: string, version: string}>
	 */
	private array $plugins = [];

	/**
	 * Versions the fake theme reader returns now.
	 *
	 * @var array<string, array{name: string, version: string}>
	 */
	private array $themes = [];

	/**
	 * Build a listener with fake readers.
	 *
	 * @param bool|null $cli Pretend to be WP-CLI.
	 */
	private function log( ?bool $cli = false ): ChangeLog {
		return new ChangeLog(
			fn ( string $file ): array => $this->plugins[ $file ] ?? [
				'name'    => '',
				'version' => '',
			],
			fn ( string $stylesheet ): array => $this->themes[ $stylesheet ] ?? [
				'name'    => '',
				'version' => '',
			],
			static fn (): string => '6.9.0',
			null,
			$cli
		);
	}

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

	public function test_all_listeners_are_registered(): void {
		$log = new ChangeLog();
		$log->register_hooks();

		$this->assertNotFalse( has_filter( 'upgrader_pre_install', [ $log, 'on_pre_install' ] ) );
		foreach ( [
			'upgrader_process_complete'  => 'on_upgrade_complete',
			'activated_plugin'           => 'on_activated',
			'deactivated_plugin'         => 'on_deactivated',
			'delete_plugin'              => 'on_delete_plugin',
			'deleted_plugin'             => 'on_deleted_plugin',
			'switch_theme'               => 'on_switch_theme',
			'_core_updated_successfully' => 'on_core_updated',
			'user_register'              => 'on_user_register',
			'delete_user'                => 'on_delete_user',
			'set_user_role'              => 'on_set_user_role',
		] as $hook => $method ) {
			$this->assertNotFalse( has_action( $hook, [ $log, $method ] ), $hook );
		}
	}

	public function test_plugin_update_records_the_version_from_before_the_upgrade(): void {
		$log                                  = $this->log();
		$this->plugins['akismet/akismet.php'] = [
			'name'    => 'Akismet',
			'version' => '5.0',
		];
		$this->plugins['hello.php']           = [
			'name'    => 'Hello Dolly',
			'version' => '1.7',
		];

		$log->on_pre_install( true, [ 'plugin' => 'akismet/akismet.php' ] );
		$log->on_pre_install( true, [ 'plugin' => 'hello.php' ] );

		// The upgrade replaces the files; hello.php stays the same (a failed bulk entry).
		$this->plugins['akismet/akismet.php']['version'] = '5.1';

		$log->on_upgrade_complete(
			(object) [],
			[
				'type'    => 'plugin',
				'action'  => 'update',
				'bulk'    => true,
				'plugins' => [ 'akismet/akismet.php', 'hello.php' ],
			]
		);

		$rows = $this->rows( 'plugin' );
		$this->assertCount( 1, $rows, 'An entry whose version did not change is not a change' );
		$this->assertSame( 'update', $rows[0]['action'] );
		$this->assertSame( 'akismet', $rows[0]['item'] );
		$this->assertSame( 'Akismet', $rows[0]['item_name'] );
		$this->assertSame( '5.0', $rows[0]['old_version'] );
		$this->assertSame( '5.1', $rows[0]['new_version'] );
		$this->assertSame( 'admin', $rows[0]['source'] );
	}

	public function test_single_plugin_update_uses_the_plugin_key(): void {
		$log                                = $this->log();
		$this->plugins['single/single.php'] = [
			'name'    => 'Single',
			'version' => '1.0',
		];
		$log->on_pre_install( true, [ 'plugin' => 'single/single.php' ] );
		$this->plugins['single/single.php']['version'] = '2.0';

		$log->on_upgrade_complete(
			(object) [],
			[
				'type'   => 'plugin',
				'action' => 'update',
				'plugin' => 'single/single.php',
			]
		);

		$this->assertSame( '1.0', $this->rows( 'plugin' )[0]['old_version'] );
	}

	public function test_automatic_updates_and_cli_updates_are_labelled(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$this->plugins['a/a.php'] = [
			'name'    => 'A',
			'version' => '1',
		];
		$this->plugins['b/b.php'] = [
			'name'    => 'B',
			'version' => '1',
		];

		$auto = $this->log();
		$auto->on_pre_install( true, [ 'plugin' => 'a/a.php' ] );
		$cli = $this->log( true );
		$cli->on_pre_install( true, [ 'plugin' => 'b/b.php' ] );
		$this->plugins['a/a.php']['version'] = '2';
		$this->plugins['b/b.php']['version'] = '2';

		$auto->on_upgrade_complete(
			(object) [ 'skin' => new \Automatic_Upgrader_Skin() ],
			[
				'type'   => 'plugin',
				'action' => 'update',
				'plugin' => 'a/a.php',
			]
		);
		$cli->on_upgrade_complete(
			(object) [],
			[
				'type'   => 'plugin',
				'action' => 'update',
				'plugin' => 'b/b.php',
			]
		);

		$by_item = array_column( $this->rows( 'plugin' ), 'source', 'item' );
		$this->assertSame( 'auto', $by_item['a'] );
		$this->assertSame( 'cli', $by_item['b'] );
	}

	public function test_plugin_install_is_logged_from_the_upgrader(): void {
		$this->plugins['fresh/fresh.php'] = [
			'name'    => 'Fresh',
			'version' => '0.9',
		];
		$upgrader                         = new class() {

			/**
			 * Plugin file the upgrader installed.
			 */
			public function plugin_info(): string {
				return 'fresh/fresh.php';
			}
		};

		$this->log()->on_upgrade_complete(
			$upgrader,
			[
				'type'   => 'plugin',
				'action' => 'install',
			]
		);

		$row = $this->rows( 'plugin' )[0];
		$this->assertSame( 'install', $row['action'] );
		$this->assertSame( 'fresh', $row['item'] );
		$this->assertSame( '', $row['old_version'] );
		$this->assertSame( '0.9', $row['new_version'] );
	}

	public function test_theme_update_and_install(): void {
		$log                        = $this->log();
		$this->themes['twentytest'] = [
			'name'    => 'Twenty Test',
			'version' => '1.0',
		];
		$log->on_pre_install( true, [ 'theme' => 'twentytest' ] );
		$this->themes['twentytest']['version'] = '1.1';

		$log->on_upgrade_complete(
			(object) [],
			[
				'type'   => 'theme',
				'action' => 'update',
				'themes' => [ 'twentytest' ],
			]
		);

		$row = $this->rows( 'theme' )[0];
		$this->assertSame( [ 'update', 'twentytest', '1.0', '1.1' ], [ $row['action'], $row['item'], $row['old_version'], $row['new_version'] ] );

		$theme    = wp_get_theme();
		$upgrader = new class( $theme ) {

			/**
			 * Constructor.
			 *
			 * @param \WP_Theme $theme Theme.
			 */
			public function __construct( private \WP_Theme $theme ) {
			}

			/**
			 * Installed theme.
			 */
			public function theme_info(): \WP_Theme {
				return $this->theme;
			}
		};
		$log->on_upgrade_complete(
			$upgrader,
			[
				'type'   => 'theme',
				'action' => 'install',
			]
		);

		$this->assertSame( 'install', $this->rows( 'theme' )[1]['action'] );
		$this->assertSame( $theme->get_stylesheet(), $this->rows( 'theme' )[1]['item'] );
	}

	public function test_core_and_translation_upgrades_are_not_logged_by_the_generic_hook(): void {
		$log = $this->log();

		$log->on_upgrade_complete(
			(object) [],
			[
				'type'   => 'core',
				'action' => 'update',
			]
		);
		$log->on_upgrade_complete(
			(object) [],
			[
				'type'   => 'translation',
				'action' => 'update',
			]
		);

		$this->assertSame( [], $this->rows( 'core' ) );
		$this->assertSame( 0, ( new ChangesRepository() )->query( [ 'type' => 'plugin' ] )['total'] );
	}

	public function test_core_update_records_old_and_new_version(): void {
		$this->log()->on_core_updated( '6.9.1' );

		$row = $this->rows( 'core' )[0];
		$this->assertSame( [ 'update', 'wordpress', 'WordPress', '6.9.0', '6.9.1', 'admin' ], [ $row['action'], $row['item'], $row['item_name'], $row['old_version'], $row['new_version'], $row['source'] ] );
	}

	public function test_core_update_during_cron_is_auto(): void {
		add_filter( 'wp_doing_cron', '__return_true' );

		$this->log()->on_core_updated( '6.9.2' );

		$this->assertSame( 'auto', $this->rows( 'core' )[0]['source'] );
	}

	public function test_activate_deactivate_and_delete(): void {
		$log                            = $this->log();
		$this->plugins['life/life.php'] = [
			'name'    => 'Life',
			'version' => '3.2',
		];

		$log->on_activated( 'life/life.php' );
		$log->on_deactivated( 'life/life.php' );
		$log->on_delete_plugin( 'life/life.php' );
		unset( $this->plugins['life/life.php'] ); // Gone from disk.
		$log->on_deleted_plugin( 'life/life.php', true );

		$rows = $this->rows( 'plugin' );
		$this->assertSame( [ 'activate', 'deactivate', 'delete' ], array_column( $rows, 'action' ) );
		$this->assertSame( [ '', '3.2', '3.2' ], array_column( $rows, 'old_version' ) );
		$this->assertSame( [ '3.2', '', '' ], array_column( $rows, 'new_version' ) );
		$this->assertSame( [ 'Life', 'Life', 'Life' ], array_column( $rows, 'item_name' ), 'A deleted plugin keeps its name' );
	}

	public function test_failed_deletion_writes_nothing(): void {
		$log = $this->log();
		$log->on_delete_plugin( 'x/x.php' );
		$log->on_deleted_plugin( 'x/x.php', false );

		$this->assertSame( [], $this->rows( 'plugin' ) );
	}

	public function test_switch_theme_records_the_activation(): void {
		$theme = wp_get_theme();

		$this->log()->on_switch_theme( $theme->get( 'Name' ), $theme, $theme );

		$row = $this->rows( 'theme' )[0];
		$this->assertSame( 'activate', $row['action'] );
		$this->assertSame( $theme->get_stylesheet(), $row['item'] );
		$this->assertSame( (string) $theme->get( 'Version' ), $row['new_version'] );
	}

	public function test_user_lifecycle_through_the_real_hooks(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$user_id = self::factory()->user->create(
			[
				'role'       => 'editor',
				'user_login' => 'amc_log_user',
			]
		);
		wp_update_user(
			[
				'ID'   => $user_id,
				'role' => 'author',
			]
		);
		wp_delete_user( $user_id );

		$rows = array_values( array_filter( $this->rows( 'user' ), static fn ( array $row ): bool => 'amc_log_user' === $row['item'] ) );

		$this->assertSame( [ 'install', 'role', 'delete' ], array_column( $rows, 'action' ), 'The first role of a new user is not logged twice' );
		$this->assertSame( [ '', 'editor', 'author' ], array_column( $rows, 'old_version' ) );
		$this->assertSame( [ 'editor', 'author', '' ], array_column( $rows, 'new_version' ) );
		$this->assertSame( get_current_user_id(), $rows[0]['user_id'], 'The acting user is recorded' );
	}

	public function test_kill_switch_stops_logging(): void {
		Settings::update( [ 'kill_switch' => true ] );

		$this->log()->on_core_updated( '7.0.0' );

		$this->assertSame( [], $this->rows( 'core' ) );
	}

	public function test_plugin_slug(): void {
		$this->assertSame( 'akismet', ChangeLog::plugin_slug( 'akismet/akismet.php' ) );
		$this->assertSame( 'hello', ChangeLog::plugin_slug( 'hello.php' ) );
	}
}
