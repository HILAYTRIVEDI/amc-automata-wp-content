<?php
/**
 * Decision logic for WordPress security configuration checks.
 *
 * Pure analysis of a facts array: no database, no HTTP, no global state, no
 * WordPress functions. The caller gathers the facts (constants, options,
 * database lookups) and passes them in, so small "check" classes can feed
 * this from any environment.
 *
 * @package AMC\Support
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- The AMC prefix belongs to AMC Copilot, whose rename is carried on another branch.

namespace AMC\Support;

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Class - SecurityConfig
 */
final class SecurityConfig {

	/**
	 * The eight WordPress secret-key constants that must each be set to a
	 * real, unique value.
	 *
	 * @var string[]
	 */
	private const SALT_NAMES = [
		'AUTH_KEY',
		'SECURE_AUTH_KEY',
		'LOGGED_IN_KEY',
		'NONCE_KEY',
		'AUTH_SALT',
		'SECURE_AUTH_SALT',
		'LOGGED_IN_SALT',
		'NONCE_SALT',
	];

	/**
	 * The placeholder WordPress installs before keys are generated.
	 *
	 * @var string
	 */
	private const DEFAULT_SALT_PLACEHOLDER = 'put your unique phrase here';

	/**
	 * The minimum length of a real secret key.
	 *
	 * @var int
	 */
	private const MIN_SALT_LENGTH = 32;

	/**
	 * Disallow instantiation - this class only exposes static helpers.
	 */
	private function __construct() {}

	/**
	 * Evaluates the security facts and returns one check item per decision.
	 *
	 * Each item is `{key, status: pass|warn|fail, title, detail}`. Salts fail
	 * when they are missing, still the default placeholder, or shorter than
	 * 32 characters.
	 *
	 * @param array<string, mixed> $facts Facts: `disallow_file_edit`, `wp_debug_display`,
	 *                                     `salts` (array of name => value), `admin_username_exists`,
	 *                                     `xmlrpc_enabled`, `uploads_listing`.
	 *
	 * @return array<int, array{key: string, status: string, title: string, detail: string}>
	 */
	public static function evaluate( array $facts ): array {
		$results = [];

		$results[] = self::file_edit_check( $facts );
		$results[] = self::debug_display_check( $facts );

		foreach ( self::SALT_NAMES as $name ) {
			$results[] = self::salt_check( $facts, $name );
		}

		$results[] = self::admin_username_check( $facts );
		$results[] = self::xmlrpc_check( $facts );
		$results[] = self::uploads_listing_check( $facts );

		return $results;
	}

	/**
	 * The `DISALLOW_FILE_EDIT` check.
	 *
	 * @param array<string, mixed> $facts Facts array.
	 *
	 * @return array{key: string, status: string, title: string, detail: string}
	 */
	private static function file_edit_check( array $facts ): array {
		if ( self::truthy( $facts, 'disallow_file_edit' ) ) {
			return [
				'key'    => 'disallow_file_edit',
				'status' => 'pass',
				'title'  => 'Plugin and theme file editing',
				'detail' => 'DISALLOW_FILE_EDIT is set, so theme and plugin files cannot be edited from wp-admin.',
			];
		}

		return [
			'key'    => 'disallow_file_edit',
			'status' => 'fail',
			'title'  => 'Plugin and theme file editing',
			'detail' => 'DISALLOW_FILE_EDIT is not set, so theme and plugin files can be edited from wp-admin. Set DISALLOW_FILE_EDIT to true.',
		];
	}

	/**
	 * The `WP_DEBUG_DISPLAY` check.
	 *
	 * @param array<string, mixed> $facts Facts array.
	 *
	 * @return array{key: string, status: string, title: string, detail: string}
	 */
	private static function debug_display_check( array $facts ): array {
		$value = $facts['wp_debug_display'] ?? null;

		if ( null === $value ) {
			return [
				'key'    => 'wp_debug_display',
				'status' => 'pass',
				'title'  => 'Debug output on live pages',
				'detail' => 'WP_DEBUG_DISPLAY is not defined, so errors are not displayed on live pages.',
			];
		}

		if ( ! self::truthy( $facts, 'wp_debug_display' ) ) {
			return [
				'key'    => 'wp_debug_display',
				'status' => 'pass',
				'title'  => 'Debug output on live pages',
				'detail' => 'WP_DEBUG_DISPLAY is off, so PHP errors are not shown to visitors.',
			];
		}

		return [
			'key'    => 'wp_debug_display',
			'status' => 'fail',
			'title'  => 'Debug output on live pages',
			'detail' => 'WP_DEBUG_DISPLAY is on, so PHP warnings and errors are shown to visitors. Set WP_DEBUG_DISPLAY to false.',
		];
	}

	/**
	 * The check for one secret-key constant.
	 *
	 * @param array<string, mixed> $facts Facts array.
	 * @param string               $name  Salt constant name, e.g. `AUTH_KEY`.
	 *
	 * @return array{key: string, status: string, title: string, detail: string}
	 */
	private static function salt_check( array $facts, string $name ): array {
		$key    = 'salt_' . strtolower( $name );
		$title  = ucwords( strtolower( str_replace( '_', ' ', $name ) ) ) . ' salt';
		$salts  = is_array( $facts['salts'] ?? null ) ? $facts['salts'] : [];
		$value  = $salts[ $name ] ?? null;
		$string = is_scalar( $value ) ? (string) $value : null;

		if ( null === $string || '' === trim( $string ) ) {
			return [
				'key'    => $key,
				'status' => 'fail',
				'title'  => $title,
				'detail' => $name . ' is missing. Generate real keys, for example from the WordPress.org secret-key service.',
			];
		}

		if ( self::DEFAULT_SALT_PLACEHOLDER === strtolower( trim( $string ) ) ) {
			return [
				'key'    => $key,
				'status' => 'fail',
				'title'  => $title,
				'detail' => $name . ' is still the default placeholder. Generate real keys, for example from the WordPress.org secret-key service.',
			];
		}

		if ( strlen( $string ) < self::MIN_SALT_LENGTH ) {
			return [
				'key'    => $key,
				'status' => 'fail',
				'title'  => $title,
				'detail' => $name . ' is only ' . strlen( $string ) . ' characters; use at least ' . self::MIN_SALT_LENGTH . '. Generate real keys, for example from the WordPress.org secret-key service.',
			];
		}

		return [
			'key'    => $key,
			'status' => 'pass',
			'title'  => $title,
			'detail' => $name . ' is set to a real, unique value.',
		];
	}

	/**
	 * The `admin` username check.
	 *
	 * @param array<string, mixed> $facts Facts array.
	 *
	 * @return array{key: string, status: string, title: string, detail: string}
	 */
	private static function admin_username_check( array $facts ): array {
		if ( ! self::truthy( $facts, 'admin_username_exists' ) ) {
			return [
				'key'    => 'admin_username_exists',
				'status' => 'pass',
				'title'  => 'Default `admin` username',
				'detail' => 'No user with the username `admin` exists, so credential-stuffing bots have no easy target.',
			];
		}

		return [
			'key'    => 'admin_username_exists',
			'status' => 'warn',
			'title'  => 'Default `admin` username',
			'detail' => 'A user with the username `admin` exists. Rename it to something unique and use a strong password with two-factor authentication.',
		];
	}

	/**
	 * The XML-RPC check.
	 *
	 * @param array<string, mixed> $facts Facts array.
	 *
	 * @return array{key: string, status: string, title: string, detail: string}
	 */
	private static function xmlrpc_check( array $facts ): array {
		if ( ! self::truthy( $facts, 'xmlrpc_enabled' ) ) {
			return [
				'key'    => 'xmlrpc_enabled',
				'status' => 'pass',
				'title'  => 'XML-RPC',
				'detail' => 'XML-RPC is disabled, so it cannot be used for brute-force amplification.',
			];
		}

		return [
			'key'    => 'xmlrpc_enabled',
			'status' => 'warn',
			'title'  => 'XML-RPC',
			'detail' => 'XML-RPC is enabled. Disable it unless a client (for example Jetpack or the mobile app) needs it.',
		];
	}

	/**
	 * The uploads directory listing check.
	 *
	 * @param array<string, mixed> $facts Facts array.
	 *
	 * @return array{key: string, status: string, title: string, detail: string}
	 */
	private static function uploads_listing_check( array $facts ): array {
		if ( ! self::truthy( $facts, 'uploads_listing' ) ) {
			return [
				'key'    => 'uploads_listing',
				'status' => 'pass',
				'title'  => 'Uploads directory listing',
				'detail' => 'Directory listing is off for /wp-content/uploads, so uploaded files are not exposed to browsers.',
			];
		}

		return [
			'key'    => 'uploads_listing',
			'status' => 'fail',
			'title'  => 'Uploads directory listing',
			'detail' => 'Directory listing is on for /wp-content/uploads, which exposes every uploaded file. Turn off autoindex for that directory.',
		];
	}

	/**
	 * Whether a fact is set to a meaningful truthy value.
	 *
	 * @param array<string, mixed> $facts Facts array.
	 * @param string               $key   Fact key.
	 *
	 * @return bool True when the fact is truthy.
	 */
	private static function truthy( array $facts, string $key ): bool {
		$value = $facts[ $key ] ?? false;

		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_scalar( $value ) ) {
			$string = strtolower( trim( (string) $value ) );

			return '' !== $string && '0' !== $string && 'false' !== $string;
		}

		return ! empty( $value );
	}
}
