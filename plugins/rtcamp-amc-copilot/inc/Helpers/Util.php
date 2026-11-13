<?php
/**
 * General-purpose plugin utility helpers.
 *
 * Stateless: final + private constructor, used statically only.
 *
 * The service accessors (logger / encryption / templates) return the plugin's
 * shared framework service from the container, so callers use the framework
 * API directly with no extra wrapping:
 *
 *   Util::logger()->info( 'Cache warmed', [ 'items' => 42 ] );
 *   $cipher = Util::encryption()->encrypt( $secret );
 *   Util::templates()->render( 'content', 'card', [ 'title' => 'Hi' ] );
 *
 * Add new shared services by adding the Core\<Service> class (implementing
 * Shareable) to Main::CLASSES and a one-line accessor below.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Helpers;

use AMC\Core\Encryption;
use AMC\Core\Logger;
use AMC\Core\Templates;
use AMC\Main;

/**
 * Class - Util
 */
final class Util {

	/**
	 * Disallow instantiation - this class only exposes static helpers.
	 */
	private function __construct() {}

	/**
	 * The plugin's shared Logger. Silent unless WP_DEBUG.
	 *
	 * @return Logger Shared logger.
	 */
	public static function logger(): Logger {
		return self::shared( Logger::class );
	}

	/**
	 * The plugin's shared Encryptor.
	 *
	 * @return Encryption Shared encryptor.
	 */
	public static function encryption(): Encryption {
		return self::shared( Encryption::class );
	}

	/**
	 * The plugin's shared template loader (child theme > parent theme > plugin).
	 *
	 * @return Templates Shared template loader.
	 */
	public static function templates(): Templates {
		return self::shared( Templates::class );
	}

	/**
	 * Resolve a shared service from the container.
	 *
	 * @template T of object
	 *
	 * @param class-string<T> $service Service class-string.
	 *
	 * @return T Shared instance.
	 */
	private static function shared( string $service ): object {
		/**
		 * Shared instance.
		 *
		 * @var T $instance
		 */
		$instance = Main::get_instance()->get_shared( $service );

		return $instance;
	}

	/**
	 * Load a plugin data file from the /inc/data/ directory.
	 *
	 * @param string $slug          File slug (no .php extension): letters, digits, hyphens
	 *                              and underscores only.
	 * @param mixed  $default_value Default value if the slug is invalid or the file is not found.
	 *
	 * @return mixed File contents or $default_value.
	 */
	public static function get_data( string $slug, mixed $default_value = [] ): mixed {
		// The slug becomes part of a require path, so refuse anything that could leave inc/data/.
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $slug ) ) {
			return $default_value;
		}

		$data_file = sprintf( AMC_COPILOT_PATH . 'inc/data/%s.php', $slug );

		if ( file_exists( $data_file ) ) {
			return require $data_file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
		}

		return $default_value;
	}

	/**
	 * Whether the current environment is production.
	 *
	 * @see https://make.wordpress.org/core/2020/07/24/new-wp_get_environment_type-function-in-wordpress-5-5/
	 */
	public static function is_production(): bool {
		return 'production' === wp_get_environment_type();
	}

	/**
	 * Whether the current User Agent matches the given kind.
	 *
	 * Delegates to Jetpack's jetpack_is_mobile() when available.
	 *
	 * @param string $kind                 Category of mobile device: 'any', 'dumb', or 'smart'.
	 * @param bool   $return_matched_agent Return the matched UA string instead of a boolean.
	 */
	public static function is_mobile( string $kind = 'any', bool $return_matched_agent = false ): bool|string {
		if ( function_exists( 'jetpack_is_mobile' ) ) {
			return jetpack_is_mobile( $kind, $return_matched_agent );
		}

		return false;
	}
}
