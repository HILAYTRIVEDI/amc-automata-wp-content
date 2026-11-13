<?php
/**
 * Facts about the site read straight from WordPress core, for the snapshot.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports;

/**
 * Class SiteFacts
 *
 * Every WordPress read the SnapshotBuilder makes lives here, so a test can
 * replace one method and the builder stays pure. Methods read current state,
 * never write, and are called from cron, CLI and REST-queued jobs only.
 *
 * @since 0.1.0
 */
class SiteFacts {

	/**
	 * Site home URL.
	 */
	public function home_url(): string {
		return home_url();
	}

	/**
	 * Site name.
	 */
	public function site_name(): string {
		return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * Current blog ID.
	 */
	public function blog_id(): int {
		return get_current_blog_id();
	}

	/**
	 * WordPress version now.
	 */
	public function wp_version(): string {
		return (string) get_bloginfo( 'version' );
	}

	/**
	 * Whether this is a multisite install.
	 */
	public function is_multisite(): bool {
		return is_multisite();
	}

	/**
	 * Every installed plugin.
	 *
	 * @return list<array{file: string, slug: string, name: string, version: string, active: bool}>
	 */
	public function plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = [];

		foreach ( get_plugins() as $file => $header ) {
			$file      = (string) $file;
			$plugins[] = [
				'file'    => $file,
				'slug'    => self::plugin_slug( $file ),
				'name'    => (string) ( $header['Name'] ?? $file ),
				'version' => (string) ( $header['Version'] ?? '' ),
				'active'  => is_plugin_active( $file ) || is_plugin_active_for_network( $file ),
			];
		}

		return $plugins;
	}

	/**
	 * Number of installed themes.
	 */
	public function themes_installed(): int {
		return count( wp_get_themes() );
	}

	/**
	 * The active theme and its parent.
	 *
	 * @return array{active: array{name: string, slug: string, version: string}, parent: array{name: string, slug: string, version: string}|null}
	 */
	public function active_theme(): array {
		$theme  = wp_get_theme();
		$parent = $theme->parent();

		return [
			'active' => [
				'name'    => (string) $theme->get( 'Name' ),
				'slug'    => $theme->get_stylesheet(),
				'version' => (string) $theme->get( 'Version' ),
			],
			'parent' => $parent instanceof \WP_Theme
				? [
					'name'    => (string) $parent->get( 'Name' ),
					'slug'    => $parent->get_stylesheet(),
					'version' => (string) $parent->get( 'Version' ),
				]
				: null,
		];
	}

	/**
	 * Block, classic or mixed editing across public post types.
	 *
	 * @return string 'block' | 'classic' | 'mixed'
	 */
	public function editor_type(): string {
		if ( ! function_exists( 'use_block_editor_for_post_type' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}

		$block   = 0;
		$classic = 0;

		foreach ( get_post_types( [ 'public' => true ] ) as $type ) {
			if ( ! post_type_supports( $type, 'editor' ) ) {
				continue;
			}

			if ( use_block_editor_for_post_type( $type ) ) {
				++$block;
			} else {
				++$classic;
			}
		}

		if ( 0 === $classic ) {
			return 'block';
		}

		return 0 === $block ? 'classic' : 'mixed';
	}

	/**
	 * Users per role and the total.
	 *
	 * @return array{total: int, roles: list<array{role: string, label: string, count: int}>}
	 */
	public function user_counts(): array {
		$counts = count_users();
		$names  = wp_roles()->get_names();
		$roles  = [];

		foreach ( $counts['avail_roles'] as $role => $count ) {
			if ( 'none' === $role ) {
				continue;
			}

			$roles[] = [
				'role'  => (string) $role,
				'label' => isset( $names[ $role ] ) ? translate_user_role( $names[ $role ] ) : (string) $role,
				'count' => (int) $count,
			];
		}

		return [
			'total' => (int) $counts['total_users'],
			'roles' => $roles,
		];
	}

	/**
	 * Administrator accounts.
	 *
	 * @return list<array{login: string, display_name: string, email: string}>
	 */
	public function administrators(): array {
		$admins = [];

		foreach ( get_users( [ 'role' => 'administrator' ] ) as $user ) {
			$admins[] = [
				'login'        => $user->user_login,
				'display_name' => $user->display_name,
				'email'        => $user->user_email,
			];
		}

		return $admins;
	}

	/**
	 * Users registered inside a window.
	 *
	 * @param string $since UTC 'Y-m-d H:i:s' (inclusive).
	 * @param string $until UTC 'Y-m-d H:i:s' (exclusive).
	 *
	 * @return list<array{login: string, role: string, registered: string}> `registered` is UTC.
	 */
	public function users_registered( string $since, string $until ): array {
		$found = [];

		$query = new \WP_User_Query(
			[
				'date_query' => [
					[
						'column'    => 'user_registered',
						'after'     => $since,
						'before'    => $until,
						'inclusive' => false,
					],
				],
				'orderby'    => 'registered',
				'order'      => 'ASC',
				'number'     => 500,
			]
		);

		foreach ( $query->get_results() as $user ) {
			if ( ! $user instanceof \WP_User ) {
				continue;
			}

			$found[] = [
				'login'      => $user->user_login,
				'role'       => (string) ( $user->roles[0] ?? '' ),
				'registered' => $user->user_registered,
			];
		}

		return $found;
	}

	/**
	 * Hosting platform name from well-known constants, or null.
	 */
	public function host(): ?string {
		$platforms = [
			'VIP_GO_APP_ENVIRONMENT' => 'WordPress VIP',
			'WPCOM_IS_VIP_ENV'       => 'WordPress VIP',
			'WPE_APIKEY'             => 'WP Engine',
			'PANTHEON_ENVIRONMENT'   => 'Pantheon',
			'KINSTA_CACHE_ZONE'      => 'Kinsta',
			'KINSTAMU_VERSION'       => 'Kinsta',
			'IS_PRESSABLE'           => 'Pressable',
		];

		foreach ( $platforms as $constant => $name ) {
			if ( defined( $constant ) ) {
				return $name;
			}
		}

		return null;
	}

	/**
	 * Operating system family the web server runs on.
	 */
	public function server_type(): ?string {
		return PHP_OS_FAMILY;
	}

	/**
	 * Web server software string, or null when not known (WP-CLI, cron).
	 */
	public function web_server(): ?string {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['SERVER_SOFTWARE'] ) ) : '';

		return '' !== $software ? $software : null;
	}

	/**
	 * PHP version.
	 */
	public function php_version(): string {
		return PHP_VERSION;
	}

	/**
	 * Database server version, or null.
	 */
	public function mysql_version(): ?string {
		global $wpdb;

		$version = $wpdb->db_version();

		return is_string( $version ) && '' !== $version ? $version : null;
	}

	/**
	 * PHP memory limit as configured (`WP_MEMORY_LIMIT` fallback).
	 */
	public function memory_limit(): ?string {
		$limit = (string) ini_get( 'memory_limit' );

		if ( '' === $limit && defined( 'WP_MEMORY_LIMIT' ) ) {
			$limit = (string) WP_MEMORY_LIMIT;
		}

		return '' !== $limit ? $limit : null;
	}

	/**
	 * A one-line recommendation about the running PHP version, or null when
	 * wordpress.org cannot be asked.
	 */
	public function php_recommendation(): ?string {
		if ( ! function_exists( 'wp_check_php_version' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		$response = wp_check_php_version();

		if ( ! is_array( $response ) ) {
			return null;
		}

		$version     = PHP_VERSION;
		$recommended = (string) ( $response['recommended_version'] ?? '' );

		if ( empty( $response['is_supported'] ) ) {
			/* translators: 1: PHP version in use, 2: recommended PHP version. */
			return sprintf( __( 'PHP %1$s is no longer supported. Update to PHP %2$s or newer.', 'rtcamp-amc-copilot' ), $version, $recommended );
		}

		if ( ! empty( $response['is_acceptable'] ) && '' !== $recommended && version_compare( $version, $recommended, '<' ) ) {
			/* translators: 1: PHP version in use, 2: recommended PHP version. */
			return sprintf( __( 'PHP %1$s is supported. PHP %2$s is recommended.', 'rtcamp-amc-copilot' ), $version, $recommended );
		}

		/* translators: %s: PHP version in use. */
		return sprintf( __( 'PHP %s is supported and up to date.', 'rtcamp-amc-copilot' ), $version );
	}

	/**
	 * Object cache description.
	 */
	public function object_cache(): string {
		return wp_using_ext_object_cache()
			? __( 'Persistent object cache', 'rtcamp-amc-copilot' )
			: __( 'None', 'rtcamp-amc-copilot' );
	}

	/**
	 * Total size of autoloaded options, in bytes.
	 */
	public function autoload_size_bytes(): int {
		$size = 0;

		foreach ( wp_load_alloptions() as $value ) {
			$size += strlen( (string) maybe_serialize( $value ) );
		}

		return $size;
	}

	/**
	 * Default report author: the lowest-ID engineer with `amc_manage`, or 0.
	 */
	public function default_author(): int {
		$engineers = \AMC\Core\Capabilities::engineers();

		if ( [] === $engineers ) {
			return 0;
		}

		$ids = array_map( static fn ( \WP_User $user ): int => (int) $user->ID, $engineers );
		sort( $ids );

		return $ids[0];
	}

	/**
	 * Plugin slug from its `dir/file.php` path.
	 *
	 * @param string $file Plugin file relative to the plugins directory.
	 */
	private static function plugin_slug( string $file ): string {
		$dir = dirname( $file );

		return '.' === $dir ? basename( $file, '.php' ) : $dir;
	}
}
