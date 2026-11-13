<?php
/**
 * Decision logic for recognising hosting platforms and web server software.
 *
 * Pure analysis of plain maps: no constants are read directly, no database,
 * no HTTP, no global state. Callers pass a map of defined constant names to
 * values plus the selected `$_SERVER` keys, so small "check" classes can feed
 * this from any environment.
 *
 * @package AMC\Support
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- The AMC prefix belongs to AMC Copilot, whose rename is carried on another branch.

namespace AMC\Support;

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Class - HostDetect
 */
final class HostDetect {

	/**
	 * Platform markers, in priority order: the first matching platform wins.
	 *
	 * @var array<string, string>
	 */
	private const HOST_CONSTANTS = [
		'WPCOM_IS_VIP_ENV'       => 'wpcom-vip',
		'VIP_GO_APP_ENVIRONMENT' => 'wpcom-vip',
		'WPE_APIKEY'             => 'wpengine',
		'PWP_NAME'               => 'wpengine',
		'PANTHEON_ENVIRONMENT'   => 'pantheon',
		'KINSTAMU_VERSION'       => 'kinsta',
		'IS_PRESSABLE'           => 'pressable',
	];

	/**
	 * Web server tokens recognised in SERVER_SOFTWARE, most specific first.
	 *
	 * @var array<string, string>
	 */
	private const WEB_SERVER_TOKENS = [
		'openlitespeed' => 'litespeed',
		'litespeed'     => 'litespeed',
		'nginx'         => 'nginx',
		'apache'        => 'apache',
		'lighttpd'      => 'lighttpd',
		'caddy'         => 'caddy',
		'iis'           => 'iis',
	];

	/**
	 * Disallow instantiation - this class only exposes static helpers.
	 */
	private function __construct() {}

	/**
	 * Detects the hosting platform and web server software.
	 *
	 * All matching markers are collected as evidence lines even though the
	 * first platform in priority order wins, so corroborating constants (for
	 * example `WPE_APIKEY` plus `PWP_NAME`) are both reported.
	 *
	 * @param array<string, mixed> $constants Map of defined constant names to values.
	 * @param array<string, mixed> $server    Selected $_SERVER keys (e.g. SERVER_SOFTWARE).
	 *
	 * @return array{host: string|null, web_server: string|null, evidence: string[]}
	 */
	public static function platform( array $constants, array $server ): array {
		$host     = null;
		$evidence = [];

		foreach ( self::HOST_CONSTANTS as $name => $platform ) {
			if ( ! array_key_exists( $name, $constants ) || ! self::meaningful( $constants[ $name ] ) ) {
				continue;
			}

			if ( null === $host ) {
				$host = $platform;
			}

			$evidence[] = 'constant ' . $name . ' = ' . self::stringify( $constants[ $name ] );
		}

		$web_server = null;
		$software   = self::server_string( $server, 'SERVER_SOFTWARE' );

		if ( null !== $software ) {
			$web_server = self::web_server( $software );

			if ( null !== $web_server ) {
				$evidence[] = 'SERVER_SOFTWARE: ' . $software;
			}
		}

		return [
			'host'       => $host,
			'web_server' => $web_server,
			'evidence'   => $evidence,
		];
	}

	/**
	 * Parses web server software from a SERVER_SOFTWARE string.
	 *
	 * @param string $software Raw SERVER_SOFTWARE value.
	 *
	 * @return string|null Lower-cased server name, or null when unknown.
	 */
	public static function web_server( string $software ): ?string {
		$software = strtolower( $software );

		foreach ( self::WEB_SERVER_TOKENS as $token => $name ) {
			if ( str_contains( $software, $token ) ) {
				return $name;
			}
		}

		return null;
	}

	/**
	 * Reads one string-valued $_SERVER key from the server map.
	 *
	 * @param array<string, mixed> $server Selected $_SERVER keys.
	 * @param string               $key    Key to read.
	 *
	 * @return string|null Trimmed value, or null when absent or empty.
	 */
	private static function server_string( array $server, string $key ): ?string {
		if ( ! array_key_exists( $key, $server ) || ! is_scalar( $server[ $key ] ) ) {
			return null;
		}

		$value = trim( (string) $server[ $key ] );

		return '' === $value ? null : $value;
	}

	/**
	 * Whether a platform marker value actually marks the platform.
	 *
	 * Defined-but-empty or defined-but-false markers (for example a plugin
	 * stub setting `IS_PRESSABLE = false`) must not match.
	 *
	 * @param mixed $value Constant value.
	 *
	 * @return bool True when the marker is meaningful.
	 */
	private static function meaningful( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( ! is_scalar( $value ) ) {
			return ! empty( $value );
		}

		$string = strtolower( trim( (string) $value ) );

		return '' !== $string && '0' !== $string && 'false' !== $string;
	}

	/**
	 * Renders a constant value for the evidence log.
	 *
	 * @param mixed $value Constant value.
	 *
	 * @return string Human-readable representation.
	 */
	private static function stringify( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		return (string) $value;
	}
}
