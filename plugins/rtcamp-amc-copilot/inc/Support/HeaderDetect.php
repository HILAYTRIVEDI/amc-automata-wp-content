<?php
/**
 * Decision logic for HTTP response headers (CDN and page cache detection).
 *
 * Pure analysis of a header map: no HTTP, no database, no global state. The
 * input must already be lower-cased by the caller; values may be strings or
 * lists of strings (as returned by curl, Guzzle, or get_headers parsing).
 *
 * @package AMC\Support
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- The AMC prefix belongs to AMC Copilot, whose rename is carried on another branch.

namespace AMC\Support;

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Class - HeaderDetect
 */
final class HeaderDetect {

	/**
	 * Cache status tokens that mean "this response came from a cache".
	 *
	 * @var string
	 */
	private const HIT_LIKE_TOKENS = '/^(hit|stale|updating|revalidate)$/i';

	/**
	 * Cache status tokens that mean "this response did not come from a cache".
	 *
	 * @var string
	 */
	private const MISS_LIKE_TOKENS = '/^(miss|dynamic|bypass|expired|none)$/i';

	/**
	 * Disallow instantiation - this class only exposes static helpers.
	 */
	private function __construct() {}

	/**
	 * Detects the CDN in front of the site from lower-cased response headers.
	 *
	 * The first provider in priority order with any evidence wins; all matching
	 * evidence lines for that provider are returned so corroborating headers
	 * (for example `cf-ray` plus `server: cloudflare`) are both reported.
	 *
	 * @param array<string, mixed> $headers Lower-cased response headers.
	 *
	 * @return array{provider: string|null, evidence: string[]}
	 */
	public static function cdn( array $headers ): array {
		$providers = [
			'cloudflare' => self::cloudflare_evidence( $headers ),
			'fastly'     => self::fastly_evidence( $headers ),
			'cloudfront' => self::cloudfront_evidence( $headers ),
			'akamai'     => self::akamai_evidence( $headers ),
			'sucuri'     => self::sucuri_evidence( $headers ),
			'vercel'     => self::vercel_evidence( $headers ),
		];

		foreach ( $providers as $provider => $evidence ) {
			if ( [] !== $evidence ) {
				return [
					'provider' => $provider,
					'evidence' => $evidence,
				];
			}
		}

		return [
			'provider' => null,
			'evidence' => [],
		];
	}

	/**
	 * Detects whether the response was served from a page cache.
	 *
	 * Explicit status headers win in the order given; a non-zero `age` header
	 * is only used when no status header is present, and an `age` of zero is
	 * inconclusive (hit stays null).
	 *
	 * @param array<string, mixed> $headers Lower-cased response headers.
	 *
	 * @return array{hit: bool|null, evidence: string[]}
	 */
	public static function page_cache( array $headers ): array {
		// Header names mapped to how their value should be interpreted.
		$status_headers = [
			'x-cache'           => 'word',
			'cf-cache-status'   => 'token',
			'x-proxy-cache'     => 'word',
			'x-litespeed-cache' => 'word',
		];

		foreach ( $status_headers as $name => $mode ) {
			foreach ( self::header_values( $headers, $name ) as $value ) {
				$hit = 'token' === $mode ? self::token_status( $value ) : self::word_status( $value );

				if ( null !== $hit ) {
					return [
						'hit'      => $hit,
						'evidence' => [ $name . ': ' . $value ],
					];
				}
			}
		}

		foreach ( self::header_values( $headers, 'age' ) as $value ) {
			if ( ctype_digit( $value ) && (int) $value > 0 ) {
				return [
					'hit'      => true,
					'evidence' => [ 'age: ' . $value . ' (non-zero age indicates a cached response)' ],
				];
			}
		}

		return [
			'hit'      => null,
			'evidence' => [],
		];
	}

	/**
	 * Evidence lines for Cloudflare.
	 *
	 * @param array<string, mixed> $headers Lower-cased response headers.
	 *
	 * @return string[]
	 */
	private static function cloudflare_evidence( array $headers ): array {
		$evidence = self::named_evidence( $headers, [ 'cf-ray', 'cf-cache-status', 'cf-request-id', 'cf-visitor', 'cf-connecting-ip' ] );

		foreach ( self::header_values( $headers, 'server' ) as $value ) {
			if ( str_contains( strtolower( $value ), 'cloudflare' ) ) {
				$evidence[] = 'server: ' . $value;
			}
		}

		return $evidence;
	}

	/**
	 * Evidence lines for Fastly.
	 *
	 * @param array<string, mixed> $headers Lower-cased response headers.
	 *
	 * @return string[]
	 */
	private static function fastly_evidence( array $headers ): array {
		$evidence = [];

		foreach ( [ 'x-served-by', 'x-cache', 'x-cache-hits', 'x-fastly-request-id' ] as $name ) {
			foreach ( self::header_values( $headers, $name ) as $value ) {
				if ( str_contains( strtolower( $value ), 'fastly' ) ) {
					$evidence[] = $name . ': ' . $value;
				}
			}
		}

		return $evidence;
	}

	/**
	 * Evidence lines for AWS CloudFront.
	 *
	 * @param array<string, mixed> $headers Lower-cased response headers.
	 *
	 * @return string[]
	 */
	private static function cloudfront_evidence( array $headers ): array {
		$evidence = self::named_evidence( $headers, [ 'x-amz-cf-id', 'x-amz-cf-pop' ] );

		foreach ( [ 'x-cache', 'via' ] as $name ) {
			foreach ( self::header_values( $headers, $name ) as $value ) {
				if ( str_contains( strtolower( $value ), 'cloudfront' ) ) {
					$evidence[] = $name . ': ' . $value;
				}
			}
		}

		return $evidence;
	}

	/**
	 * Evidence lines for Akamai.
	 *
	 * @param array<string, mixed> $headers Lower-cased response headers.
	 *
	 * @return string[]
	 */
	private static function akamai_evidence( array $headers ): array {
		$evidence = [];

		foreach ( $headers as $name => $value ) {
			$name = strtolower( (string) $name );

			if ( str_starts_with( $name, 'x-akamai-' ) ) {
				foreach ( self::header_values( [ $name => $value ], $name ) as $line ) {
					$evidence[] = $name . ': ' . $line;
				}
			}
		}

		foreach ( self::header_values( $headers, 'server' ) as $value ) {
			if ( str_contains( strtolower( $value ), 'akamai' ) ) {
				$evidence[] = 'server: ' . $value;
			}
		}

		return $evidence;
	}

	/**
	 * Evidence lines for Sucuri.
	 *
	 * @param array<string, mixed> $headers Lower-cased response headers.
	 *
	 * @return string[]
	 */
	private static function sucuri_evidence( array $headers ): array {
		$evidence = self::named_evidence( $headers, [ 'x-sucuri-id', 'x-sucuri-cache' ] );

		foreach ( self::header_values( $headers, 'server' ) as $value ) {
			if ( str_contains( strtolower( $value ), 'sucuri' ) ) {
				$evidence[] = 'server: ' . $value;
			}
		}

		return $evidence;
	}

	/**
	 * Evidence lines for Vercel.
	 *
	 * @param array<string, mixed> $headers Lower-cased response headers.
	 *
	 * @return string[]
	 */
	private static function vercel_evidence( array $headers ): array {
		$evidence = [];

		foreach ( $headers as $name => $value ) {
			$name = strtolower( (string) $name );

			if ( str_starts_with( $name, 'x-vercel-' ) ) {
				foreach ( self::header_values( [ $name => $value ], $name ) as $line ) {
					$evidence[] = $name . ': ' . $line;
				}
			}
		}

		return $evidence;
	}

	/**
	 * Collects `name: value` lines for an exact list of header names.
	 *
	 * @param array<string, mixed> $headers Lower-cased response headers.
	 * @param string[]             $names   Header names to collect.
	 *
	 * @return string[]
	 */
	private static function named_evidence( array $headers, array $names ): array {
		$evidence = [];

		foreach ( $names as $name ) {
			foreach ( self::header_values( $headers, $name ) as $value ) {
				$evidence[] = $name . ': ' . $value;
			}
		}

		return $evidence;
	}

	/**
	 * Interprets a cache status value made of arbitrary words, such as
	 * `HIT`, `Miss from cloudfront`, or `TCP_HIT, TCP_MISS`.
	 *
	 * Any hit token wins; otherwise any miss token fails it.
	 *
	 * @param string $value Raw header value.
	 *
	 * @return bool|null True for a hit, false for a miss, null if inconclusive.
	 */
	private static function word_status( string $value ): ?bool {
		if ( 1 === preg_match( '/(?<![a-z])hit(?![a-z])/i', $value ) ) {
			return true;
		}

		if ( 1 === preg_match( '/(?<![a-z])miss(?![a-z])/i', $value ) ) {
			return false;
		}

		return null;
	}

	/**
	 * Interprets a single-token cache status such as `cf-cache-status: DYNAMIC`.
	 *
	 * @param string $value Raw header value.
	 *
	 * @return bool|null True for a hit-like token, false for a miss-like one.
	 */
	private static function token_status( string $value ): ?bool {
		$token = strtolower( trim( $value ) );

		if ( 1 === preg_match( self::HIT_LIKE_TOKENS, $token ) ) {
			return true;
		}

		if ( 1 === preg_match( self::MISS_LIKE_TOKENS, $token ) ) {
			return false;
		}

		return null;
	}

	/**
	 * Normalises the values of one header to a list of trimmed strings.
	 *
	 * Accepts string values and lists of strings, and matches header names
	 * case-insensitively so callers can pass raw curl/Guzzle output.
	 *
	 * @param array<string, mixed> $headers Lower-cased response headers.
	 * @param string               $name    Header name to read.
	 *
	 * @return string[]
	 */
	private static function header_values( array $headers, string $name ): array {
		$values = [];

		foreach ( $headers as $key => $value ) {
			if ( strtolower( (string) $key ) !== $name ) {
				continue;
			}

			foreach ( (array) $value as $single ) {
				if ( is_scalar( $single ) ) {
					$values[] = trim( (string) $single );
				}
			}
		}

		return $values;
	}
}
