<?php
/**
 * Loads the wp-admin include that defines a core admin function.
 *
 * @package AMC\Support
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- The AMC prefix belongs to AMC Copilot, whose rename is carried on another branch.

namespace AMC\Support;

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Class - AdminFunctions
 *
 * Checks run from WP-Cron, WP-CLI and REST as well as wp-admin. Outside
 * wp-admin, core does not load `wp-admin/includes/*`, so functions such as
 * `wp_check_php_version()` or `get_plugins()` may be missing and a check that
 * guards on `function_exists()` silently reports "no data". Call `ensure()`
 * first so the function is there in every context.
 *
 * @since 0.1.0
 */
final class AdminFunctions {

	/**
	 * Core admin functions the checks use, mapped to the file under
	 * `wp-admin/includes/` that defines them.
	 *
	 * @var array<string, string>
	 */
	private const FILES = [
		'get_plugins'          => 'plugin.php',
		'get_mu_plugins'       => 'plugin.php',
		'get_dropins'          => 'plugin.php',
		'_get_dropins'         => 'plugin.php',
		'wp_check_php_version' => 'misc.php',
		'plugins_api'          => 'plugin-install.php',
		'themes_api'           => 'theme.php',
	];

	/**
	 * Make sure a core admin function is defined, loading its file if needed.
	 *
	 * @param string $function_name Function name, a key of FILES.
	 *
	 * @return bool Whether the function is now defined.
	 */
	public static function ensure( string $function_name ): bool {
		if ( function_exists( $function_name ) ) {
			return true;
		}

		$file = self::FILES[ $function_name ] ?? null;

		if ( null === $file || ! defined( 'ABSPATH' ) ) {
			return false;
		}

		$path = ABSPATH . 'wp-admin/includes/' . $file;

		if ( ! is_readable( $path ) ) {
			return false;
		}

		require_once $path;

		return function_exists( $function_name );
	}
}
