<?php
/**
 * Signatures, sources and normalisation for captured events.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Capture;

/**
 * Class Signature
 *
 * Pure helpers (no database, no options). They run at flush time, never on the
 * hot path of a request.
 *
 * - `message()`: normalises an error message so that two occurrences of the
 *   same problem collapse into one signature (paths become `<path>`, numbers
 *   and hex addresses become `N`).
 * - `of()`: `md5( type | file | line | normalised message )`, the value stored
 *   in `amc_events.signature` (docs/CONTRACTS.md section 7).
 * - `relative_file()`: file path relative to the WordPress root, so a signature
 *   is identical on every host.
 * - `source()`: `core`, `plugin:<slug>`, `theme:<slug>`, `mu-plugin:<file>` or `unknown`.
 * - `sql()`: normalises SQL for the slow-query signature (literals become `?`).
 *
 * @since 0.1.0
 */
final class Signature {

	/**
	 * Normalised directory roots (each with a trailing slash), built once per request.
	 *
	 * @var array{abspath: string, content: string, plugins: string, mu: string, themes: string}|null
	 */
	private static ?array $roots = null;

	/**
	 * Normalise an error message.
	 *
	 * @param string $message Raw message.
	 */
	public static function message( string $message ): string {
		$message = (string) preg_replace( '#(?<![\w:/.])(?:[A-Za-z]:[\\\\/]|/)[^\s\'"()<>:,;]+#', '<path>', $message );
		$message = (string) preg_replace( '/\b0x[0-9a-f]+\b/i', 'N', $message );

		return trim( (string) preg_replace( '/\d+/', 'N', $message ) );
	}

	/**
	 * Event signature.
	 *
	 * @param string $type    Event type (fatal, warning, ...).
	 * @param string $file    File, as stored (see relative_file()).
	 * @param int    $line    Line.
	 * @param string $message Raw message.
	 *
	 * @return string 32-character md5.
	 */
	public static function of( string $type, string $file, int $line, string $message ): string {
		return md5( $type . '|' . $file . '|' . $line . '|' . self::message( $message ) );
	}

	/**
	 * File path relative to the WordPress root (or to the parent of wp-content).
	 *
	 * @param string $file Absolute path.
	 */
	public static function relative_file( string $file ): string {
		if ( '' === $file ) {
			return '';
		}

		$roots = self::roots();
		$file  = self::normalise_path( $file );

		if ( str_starts_with( $file, $roots['abspath'] ) ) {
			return substr( $file, strlen( $roots['abspath'] ) );
		}

		$parent = self::normalise_path( dirname( rtrim( $roots['content'], '/' ) ) );
		$parent = '/' === $parent ? '/' : trailingslashit( $parent );
		if ( str_starts_with( $file, $parent ) ) {
			return substr( $file, strlen( $parent ) );
		}

		return $file;
	}

	/**
	 * Which part of the site a file belongs to.
	 *
	 * @param string $file Absolute path.
	 *
	 * @return string 'core', 'plugin:<slug>', 'theme:<slug>', 'mu-plugin:<file>' or 'unknown'.
	 */
	public static function source( string $file ): string {
		if ( '' === $file ) {
			return 'unknown';
		}

		$roots = self::roots();
		$file  = self::normalise_path( $file );

		$mu = self::first_segment( $file, $roots['mu'] );
		if ( null !== $mu ) {
			return 'mu-plugin:' . $mu;
		}

		$plugin = self::first_segment( $file, $roots['plugins'] );
		if ( null !== $plugin ) {
			return 'plugin:' . ( str_ends_with( $plugin, '.php' ) ? substr( $plugin, 0, -4 ) : $plugin );
		}

		$theme = self::first_segment( $file, $roots['themes'] );
		if ( null !== $theme ) {
			return 'theme:' . $theme;
		}

		if ( str_starts_with( $file, $roots['abspath'] ) ) {
			$relative = substr( $file, strlen( $roots['abspath'] ) );
			if ( str_starts_with( $relative, 'wp-includes/' ) || str_starts_with( $relative, 'wp-admin/' ) || 1 === preg_match( '#^[^/]+\.php$#', $relative ) ) {
				return 'core';
			}
		}

		return 'unknown';
	}

	/**
	 * Normalise SQL: strip comments and literals, collapse IN lists and whitespace.
	 *
	 * @param string $sql Raw SQL.
	 */
	public static function sql( string $sql ): string {
		$sql = (string) preg_replace( '#/\*.*?\*/#s', ' ', $sql );
		$sql = (string) preg_replace( "/'(?:[^'\\\\\\\\]|\\\\\\\\.|'')*'/s", '?', $sql );
		$sql = (string) preg_replace( '/"(?:[^"\\\\\\\\]|\\\\\\\\.)*"/s', '?', $sql );
		$sql = (string) preg_replace( '/\b0x[0-9a-f]+\b/i', '?', $sql );
		$sql = (string) preg_replace( '/\b\d+(?:\.\d+)?\b/', '?', $sql );
		$sql = (string) preg_replace( '/\(\s*\?(?:\s*,\s*\?)+\s*\)/', '(?)', $sql );

		return trim( (string) preg_replace( '/\s+/', ' ', $sql ) );
	}

	/**
	 * Forget the cached directory roots (tests).
	 */
	public static function reset(): void {
		self::$roots = null;
	}

	/**
	 * First path segment of $file below $root, or null when $file is not below it.
	 *
	 * @param string $file Normalised file.
	 * @param string $root Normalised root with trailing slash.
	 */
	private static function first_segment( string $file, string $root ): ?string {
		if ( ! str_starts_with( $file, $root ) ) {
			return null;
		}

		$relative = substr( $file, strlen( $root ) );
		$segment  = strstr( $relative, '/', true );
		$segment  = false === $segment ? $relative : $segment;

		return '' === $segment ? null : $segment;
	}

	/**
	 * Slashes only; no realpath() (no filesystem access).
	 *
	 * @param string $path Path.
	 */
	private static function normalise_path( string $path ): string {
		return str_replace( '\\', '/', $path );
	}

	/**
	 * Directory roots.
	 *
	 * @return array{abspath: string, content: string, plugins: string, mu: string, themes: string}
	 */
	private static function roots(): array {
		if ( null === self::$roots ) {
			$content     = trailingslashit( self::normalise_path( WP_CONTENT_DIR ) );
			self::$roots = [
				'abspath' => trailingslashit( self::normalise_path( ABSPATH ) ),
				'content' => $content,
				'plugins' => trailingslashit( self::normalise_path( WP_PLUGIN_DIR ) ),
				'mu'      => trailingslashit( self::normalise_path( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : $content . 'mu-plugins' ) ),
				'themes'  => $content . 'themes/',
			];
		}

		return self::$roots;
	}
}
