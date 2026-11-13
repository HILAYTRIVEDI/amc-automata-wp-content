<?php
/**
 * Change log listeners: plugin, theme, core and user changes.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Capture;

use AMC\Core\KillSwitch;
use AMC\Storage\ChangesRepository;
use WP_Theme;
use WP_User;
use rtCamp\WPPrimitives\Contracts\Interfaces\Registrable;

/**
 * Class ChangeLog
 *
 * Writes one `amc_changes` row per event. Nothing runs (and nothing is read
 * from the database) until one of these hooks fires, which is admin, cron or
 * CLI work.
 *
 * | Hook | Row |
 * | --- | --- |
 * | `upgrader_pre_install` | no row; remembers the version BEFORE the upgrade |
 * | `upgrader_process_complete` | plugin/theme `update` (old and new version) or `install` |
 * | `activated_plugin`, `deactivated_plugin` | plugin `activate` / `deactivate` |
 * | `delete_plugin` + `deleted_plugin` | plugin `delete` (name and version taken before deletion) |
 * | `switch_theme` | theme `activate` |
 * | `_core_updated_successfully` | core `update`, item `wordpress` |
 * | `user_register`, `delete_user`, `set_user_role` | user `install`, `delete`, `role` |
 *
 * Source: `auto` for automatic updates (Automatic_Upgrader_Skin, or cron for
 * core), `cli` when WP_CLI is defined, otherwise `admin`. The kill switch
 * stops logging.
 *
 * Core updates are logged from `_core_updated_successfully` only;
 * `upgrader_process_complete` fires for them too and is ignored.
 *
 * @since 0.1.0
 */
final class ChangeLog implements Registrable {

	/**
	 * Versions and names captured before an upgrade or deletion.
	 *
	 * @var array<string, array{name: string, version: string}>
	 */
	private array $before = [];

	/**
	 * Reads a plugin file: fn( string $plugin_file ): array{name: string, version: string}.
	 *
	 * @var \Closure
	 */
	private \Closure $plugin_reader;

	/**
	 * Reads a theme: fn( string $stylesheet ): array{name: string, version: string}.
	 *
	 * @var \Closure
	 */
	private \Closure $theme_reader;

	/**
	 * Running WordPress version: fn(): string.
	 *
	 * @var \Closure
	 */
	private \Closure $core_version;

	/**
	 * Constructor.
	 *
	 * @param \Closure|null          $plugin_reader Plugin reader (test seam).
	 * @param \Closure|null          $theme_reader  Theme reader (test seam).
	 * @param \Closure|null          $core_version  Core version reader (test seam).
	 * @param ChangesRepository|null $changes       Repository (test seam).
	 * @param bool|null              $cli           Whether this is WP-CLI (test seam; default: WP_CLI).
	 */
	public function __construct(
		?\Closure $plugin_reader = null,
		?\Closure $theme_reader = null,
		?\Closure $core_version = null,
		private ?ChangesRepository $changes = null,
		private readonly ?bool $cli = null
	) {
		$this->plugin_reader = $plugin_reader ?? static fn ( string $file ): array => self::read_plugin( $file );
		$this->theme_reader  = $theme_reader ?? static fn ( string $stylesheet ): array => self::read_theme( $stylesheet );
		$this->core_version  = $core_version ?? static fn (): string => wp_get_wp_version();
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_filter( 'upgrader_pre_install', [ $this, 'on_pre_install' ], 10, 2 );
		add_action( 'upgrader_process_complete', [ $this, 'on_upgrade_complete' ], 10, 2 );
		add_action( 'activated_plugin', [ $this, 'on_activated' ] );
		add_action( 'deactivated_plugin', [ $this, 'on_deactivated' ] );
		add_action( 'delete_plugin', [ $this, 'on_delete_plugin' ] );
		add_action( 'deleted_plugin', [ $this, 'on_deleted_plugin' ], 10, 2 );
		add_action( 'switch_theme', [ $this, 'on_switch_theme' ], 10, 3 );
		add_action( '_core_updated_successfully', [ $this, 'on_core_updated' ] );
		add_action( 'user_register', [ $this, 'on_user_register' ] );
		add_action( 'delete_user', [ $this, 'on_delete_user' ], 10, 3 );
		add_action( 'set_user_role', [ $this, 'on_set_user_role' ], 10, 3 );
	}

	/**
	 * Plugin slug for a plugin file ('akismet/akismet.php' => 'akismet', 'hello.php' => 'hello').
	 *
	 * @param string $plugin_file Path relative to the plugins directory.
	 */
	public static function plugin_slug( string $plugin_file ): string {
		$dir = dirname( $plugin_file );

		return '.' === $dir || '' === $dir ? basename( $plugin_file, '.php' ) : $dir;
	}

	/**
	 * `upgrader_pre_install` filter: remember what is installed before it is replaced.
	 *
	 * @param mixed $response   Response (returned untouched).
	 * @param mixed $hook_extra What is being upgraded.
	 */
	public function on_pre_install( mixed $response, mixed $hook_extra = [] ): mixed {
		if ( is_array( $hook_extra ) ) {
			if ( ! empty( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) ) {
				$this->before[ 'plugin:' . $hook_extra['plugin'] ] = ( $this->plugin_reader )( $hook_extra['plugin'] );
			}
			if ( ! empty( $hook_extra['theme'] ) && is_string( $hook_extra['theme'] ) ) {
				$this->before[ 'theme:' . $hook_extra['theme'] ] = ( $this->theme_reader )( $hook_extra['theme'] );
			}
		}

		return $response;
	}

	/**
	 * `upgrader_process_complete`.
	 *
	 * @param mixed $upgrader   WP_Upgrader.
	 * @param mixed $hook_extra What happened.
	 */
	public function on_upgrade_complete( mixed $upgrader, mixed $hook_extra = [] ): void {
		if ( ! is_array( $hook_extra ) ) {
			return;
		}

		$type   = (string) ( $hook_extra['type'] ?? '' );
		$action = (string) ( $hook_extra['action'] ?? '' );
		$source = $this->source( is_object( $upgrader ) && isset( $upgrader->skin ) && $upgrader->skin instanceof \Automatic_Upgrader_Skin );

		if ( 'plugin' === $type && 'update' === $action ) {
			foreach ( $this->listed( $hook_extra, 'plugins', 'plugin' ) as $file ) {
				$this->log_plugin_update( $file, $source );
			}
		} elseif ( 'plugin' === $type && 'install' === $action && is_object( $upgrader ) && method_exists( $upgrader, 'plugin_info' ) ) {
			$file = $upgrader->plugin_info();
			if ( is_string( $file ) && '' !== $file ) {
				$now = ( $this->plugin_reader )( $file );
				$this->record( 'plugin', 'install', self::plugin_slug( $file ), $now['name'], '', $now['version'], $source );
			}
		} elseif ( 'theme' === $type && 'update' === $action ) {
			foreach ( $this->listed( $hook_extra, 'themes', 'theme' ) as $stylesheet ) {
				$old = $this->before[ 'theme:' . $stylesheet ] ?? [
					'name'    => '',
					'version' => '',
				];
				$now = ( $this->theme_reader )( $stylesheet );
				$this->record_update( 'theme', $stylesheet, $now['name'] ?: $old['name'], $old['version'], $now['version'], $source );
			}
		} elseif ( 'theme' === $type && 'install' === $action && is_object( $upgrader ) && method_exists( $upgrader, 'theme_info' ) ) {
			$theme = $upgrader->theme_info();
			if ( $theme instanceof WP_Theme ) {
				$this->record( 'theme', 'install', $theme->get_stylesheet(), (string) $theme->get( 'Name' ), '', (string) $theme->get( 'Version' ), $source );
			}
		}
	}

	/**
	 * `activated_plugin`.
	 *
	 * @param string $plugin Plugin file.
	 */
	public function on_activated( string $plugin ): void {
		$now = ( $this->plugin_reader )( $plugin );
		$this->record( 'plugin', 'activate', self::plugin_slug( $plugin ), $now['name'], '', $now['version'] );
	}

	/**
	 * `deactivated_plugin`.
	 *
	 * @param string $plugin Plugin file.
	 */
	public function on_deactivated( string $plugin ): void {
		$now = ( $this->plugin_reader )( $plugin );
		$this->record( 'plugin', 'deactivate', self::plugin_slug( $plugin ), $now['name'], $now['version'], '' );
	}

	/**
	 * `delete_plugin`: still on disk, take name and version.
	 *
	 * @param string $plugin_file Plugin file.
	 */
	public function on_delete_plugin( string $plugin_file ): void {
		$this->before[ 'delete:' . $plugin_file ] = ( $this->plugin_reader )( $plugin_file );
	}

	/**
	 * `deleted_plugin`.
	 *
	 * @param string $plugin_file Plugin file.
	 * @param bool   $deleted     Whether the deletion worked.
	 */
	public function on_deleted_plugin( string $plugin_file, bool $deleted ): void {
		if ( ! $deleted ) {
			return;
		}

		$was = $this->before[ 'delete:' . $plugin_file ] ?? [
			'name'    => '',
			'version' => '',
		];
		$this->record( 'plugin', 'delete', self::plugin_slug( $plugin_file ), $was['name'], $was['version'], '' );
	}

	/**
	 * `switch_theme`.
	 *
	 * @param string $new_name  New theme name.
	 * @param mixed  $new_theme New WP_Theme.
	 * @param mixed  $old_theme Previous WP_Theme (unused).
	 */
	public function on_switch_theme( string $new_name, mixed $new_theme = null, mixed $old_theme = null ): void {
		unset( $old_theme );
		if ( ! $new_theme instanceof WP_Theme ) {
			return;
		}

		$this->record( 'theme', 'activate', $new_theme->get_stylesheet(), '' !== $new_name ? $new_name : (string) $new_theme->get( 'Name' ), '', (string) $new_theme->get( 'Version' ) );
	}

	/**
	 * `_core_updated_successfully`: the running code is still the old version.
	 *
	 * @param string $new_version New WordPress version.
	 */
	public function on_core_updated( string $new_version ): void {
		$item = strtolower( 'WordPress' ); // The item slug is lower case (docs/CONTRACTS.md section 7).

		$this->record_update( 'core', $item, 'WordPress', ( $this->core_version )(), $new_version, $this->source( wp_doing_cron() ) );
	}

	/**
	 * `user_register`.
	 *
	 * @param int $user_id User ID.
	 */
	public function on_user_register( int $user_id ): void {
		$user = get_userdata( $user_id );

		if ( $user instanceof WP_User ) {
			$this->record( 'user', 'install', $user->user_login, $user->display_name, '', implode( ',', $user->roles ) );
		}
	}

	/**
	 * `delete_user` (runs before the deletion).
	 *
	 * @param int   $user_id  User ID.
	 * @param mixed $reassign Reassign target.
	 * @param mixed $user     WP_User.
	 */
	public function on_delete_user( int $user_id, mixed $reassign = null, mixed $user = null ): void {
		unset( $reassign );
		$user = $user instanceof WP_User ? $user : get_userdata( $user_id );

		if ( $user instanceof WP_User ) {
			$this->record( 'user', 'delete', $user->user_login, $user->display_name, implode( ',', $user->roles ), '' );
		}
	}

	/**
	 * `set_user_role`. The first role of a brand-new user is part of its
	 * `user_register` row, so a change from "no role" is not logged twice.
	 *
	 * @param int           $user_id   User ID.
	 * @param string        $role      New role.
	 * @param array<string> $old_roles Previous roles.
	 */
	public function on_set_user_role( int $user_id, string $role, array $old_roles = [] ): void {
		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User || [] === $old_roles ) {
			return;
		}

		$this->record( 'user', 'role', $user->user_login, $user->display_name, implode( ',', $old_roles ), $role );
	}

	/**
	 * Log one plugin update from the pre-install snapshot and the files now on disk.
	 *
	 * @param string $file   Plugin file.
	 * @param string $source Change source.
	 */
	private function log_plugin_update( string $file, string $source ): void {
		$old = $this->before[ 'plugin:' . $file ] ?? [
			'name'    => '',
			'version' => '',
		];
		$now = ( $this->plugin_reader )( $file );

		$this->record_update( 'plugin', self::plugin_slug( $file ), $now['name'] ?: $old['name'], $old['version'], $now['version'], $source );
	}

	/**
	 * Record an update, skipping bulk entries that did not change version.
	 *
	 * @param string $type        plugin | theme | core.
	 * @param string $item        Slug.
	 * @param string $name        Display name.
	 * @param string $old_version Old version.
	 * @param string $new_version New version.
	 * @param string $source      Change source.
	 */
	private function record_update( string $type, string $item, string $name, string $old_version, string $new_version, string $source ): void {
		if ( '' !== $old_version && $old_version === $new_version ) {
			return;
		}

		$this->record( $type, 'update', $item, $name, $old_version, $new_version, $source );
	}

	/**
	 * Items of a bulk or single upgrade.
	 *
	 * @param array<mixed> $hook_extra Hook data.
	 * @param string       $plural     Key holding the list.
	 * @param string       $singular   Key holding one item.
	 *
	 * @return list<string>
	 */
	private function listed( array $hook_extra, string $plural, string $singular ): array {
		$items = isset( $hook_extra[ $plural ] ) ? (array) $hook_extra[ $plural ] : [];
		if ( [] === $items && isset( $hook_extra[ $singular ] ) ) {
			$items = [ $hook_extra[ $singular ] ];
		}

		return array_values( array_filter( array_map( 'strval', $items ), static fn ( string $item ): bool => '' !== $item ) );
	}

	/**
	 * Source label.
	 *
	 * @param bool $auto Whether this is an automatic update.
	 */
	private function source( bool $auto = false ): string {
		if ( $auto ) {
			return 'auto';
		}

		$cli = $this->cli ?? ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) );

		return $cli ? 'cli' : 'admin';
	}

	/**
	 * Write one row, unless the kill switch is on.
	 *
	 * @param string      $type        Change type.
	 * @param string      $action      Change action.
	 * @param string      $item        Item.
	 * @param string      $name        Display name.
	 * @param string      $old_version Old version.
	 * @param string      $new_version New version.
	 * @param string|null $source      Source (default: derived).
	 */
	private function record( string $type, string $action, string $item, string $name, string $old_version, string $new_version, ?string $source = null ): void {
		if ( KillSwitch::is_active() ) {
			return;
		}

		$this->changes ??= new ChangesRepository();
		$this->changes->insert(
			[
				'type'        => $type,
				'action'      => $action,
				'item'        => $item,
				'item_name'   => $name,
				'old_version' => $old_version,
				'new_version' => $new_version,
				'source'      => $source ?? $this->source(),
			]
		);
	}

	/**
	 * Name and version from a plugin file header.
	 *
	 * @param string $plugin_file Path relative to the plugins directory.
	 *
	 * @return array{name: string, version: string}
	 */
	private static function read_plugin( string $plugin_file ): array {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$path = trailingslashit( WP_PLUGIN_DIR ) . $plugin_file;
		$data = is_readable( $path ) ? get_plugin_data( $path, false, false ) : [];

		return [
			'name'    => (string) ( $data['Name'] ?? '' ),
			'version' => (string) ( $data['Version'] ?? '' ),
		];
	}

	/**
	 * Name and version of an installed theme.
	 *
	 * @param string $stylesheet Theme directory.
	 *
	 * @return array{name: string, version: string}
	 */
	private static function read_theme( string $stylesheet ): array {
		$theme = wp_get_theme( $stylesheet );

		return $theme->exists()
			? [
				'name'    => (string) $theme->get( 'Name' ),
				'version' => (string) $theme->get( 'Version' ),
			]
			: [
				'name'    => '',
				'version' => '',
			];
	}
}
