<?php
/**
 * Decision logic for the home page smoke check.
 *
 * Pure analysis of a check result: no HTTP, no database, no global state. The
 * caller performs the request itself and passes the status code, the time it
 * took, the body, and any transport error, so small "check" classes can feed
 * this from any transport.
 *
 * @package AMC\Support
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- The AMC prefix belongs to AMC Copilot, whose rename is carried on another branch.

namespace AMC\Support;

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Class - SmokeRule
 */
final class SmokeRule {

	/**
	 * The number of seconds a smoke check may take before it fails.
	 *
	 * @var float
	 */
	private const TIME_LIMIT_SECONDS = 3.0;

	/**
	 * PHP error patterns that make a page body unhealthy.
	 *
	 * Warning/Notice/Deprecated only count when followed by a `in /… on line N`
	 * pair, so editorial content quoting a "warning" does not fail the check.
	 *
	 * @var array<string, string>
	 */
	private const PHP_ERROR_PATTERNS = [
		'fatal PHP error'       => '/fatal error:/i',
		'PHP parse error'       => '/parse error:/i',
		'PHP warning or notice' => '/\b(?:warning|notice|deprecated):.{0,300}?in\s+\/.{0,300}?on line\s+\d+/is',
		'WordPress fatal error' => '/there has been a critical error on this website/i',
	];

	/**
	 * Transport errors that mean the site's own host could not be reached at
	 * all (DNS, refused, or an authentication wall), which blocks the loopback
	 * check rather than failing the site.
	 *
	 * Timeouts are deliberately NOT here: a site that hangs past the request
	 * timeout is exactly what a smoke check must report, so a timeout fails.
	 *
	 * @var string[]
	 */
	private const LOOPBACK_PATTERNS = [
		'/curl error 6\b/i',
		'/curl error 7\b/i',
		'/could not resolve host/i',
		'/connection refused/i',
		'/\b401\b/u',
		'/unauthorized/i',
	];

	/**
	 * Disallow instantiation - this class only exposes static helpers.
	 */
	private function __construct() {}

	/**
	 * Evaluates a smoke check result against the pass rule.
	 *
	 * Pass rule: HTTP 200, under 3 seconds, no PHP error text in the body, and
	 * the required text present when one is given. A transport error on the
	 * site's own host (DNS failure, connection refused, timeout, or an HTTP
	 * 401 basic-auth wall) is `loopback_blocked`, not `fail`, because the site
	 * itself could not be observed.
	 *
	 * @param int         $status          HTTP status code of the response.
	 * @param float       $seconds         Request duration in seconds.
	 * @param string      $body            Response body.
	 * @param string|null $required_text   Text that must appear in the body, or null.
	 * @param string|null $transport_error Transport error message, or null.
	 *
	 * `codes` are stable machine-readable reason identifiers (`http_status`,
	 * `too_slow`, `php_error`, `required_text_missing`, `auth_wall`, `loopback`,
	 * `transport_error`), one per entry in `reasons`, so callers can translate.
	 *
	 * @return array{status: 'pass'|'fail'|'loopback_blocked', reasons: string[], codes: string[]}
	 */
	public static function evaluate(
		int $status,
		float $seconds,
		string $body,
		?string $required_text = null,
		?string $transport_error = null
	): array {
		if ( null !== $transport_error && '' !== trim( $transport_error ) ) {
			return self::transport_verdict( $transport_error );
		}

		$reasons = [];
		$codes   = [];

		if ( 401 === $status ) {
			return self::blocked( [ 'HTTP 401: the site is behind basic auth or an access wall.' ], [ 'auth_wall' ] );
		}

		if ( 200 !== $status ) {
			$reasons[] = 'HTTP status ' . $status . ', expected 200.';
			$codes[]   = 'http_status';
		}

		if ( $seconds >= self::TIME_LIMIT_SECONDS ) {
			$reasons[] = sprintf( 'Response took %.2fs, over the %.2fs limit.', $seconds, self::TIME_LIMIT_SECONDS );
			$codes[]   = 'too_slow';
		}

		// PHP's html_errors wraps the labels in <b> tags ("<b>Warning</b>: ... in <b>/path</b>"); drop them so those still match.
		$scan = preg_replace( '#</?b>#i', '', $body ) ?? $body;

		foreach ( self::PHP_ERROR_PATTERNS as $label => $pattern ) {
			if ( 1 === preg_match( $pattern, $scan ) ) {
				$reasons[] = 'PHP error text in body (' . $label . ').';
				$codes[]   = 'php_error';
			}
		}

		if ( null !== $required_text && '' !== trim( $required_text ) && ! str_contains( $body, $required_text ) ) {
			$reasons[] = 'Required text not found in body.';
			$codes[]   = 'required_text_missing';
		}

		return [
			'status'  => [] === $reasons ? 'pass' : 'fail',
			'reasons' => $reasons,
			'codes'   => $codes,
		];
	}

	/**
	 * Verdict for a transport error.
	 *
	 * @param string $transport_error Transport error message.
	 *
	 * @return array{status: 'pass'|'fail'|'loopback_blocked', reasons: string[], codes: string[]}
	 */
	private static function transport_verdict( string $transport_error ): array {
		foreach ( self::LOOPBACK_PATTERNS as $pattern ) {
			if ( 1 === preg_match( $pattern, $transport_error ) ) {
				return self::blocked( [ 'Transport error indicates a blocked loopback request: ' . $transport_error ], [ 'loopback' ] );
			}
		}

		return [
			'status'  => 'fail',
			'reasons' => [ 'Transport error: ' . $transport_error ],
			'codes'   => [ 'transport_error' ],
		];
	}

	/**
	 * A loopback-blocked verdict.
	 *
	 * @param string[] $reasons Why the loopback is blocked.
	 * @param string[] $codes   Reason codes.
	 *
	 * @return array{status: 'pass'|'fail'|'loopback_blocked', reasons: string[], codes: string[]}
	 */
	private static function blocked( array $reasons, array $codes ): array {
		return [
			'status'  => 'loopback_blocked',
			'reasons' => $reasons,
			'codes'   => $codes,
		];
	}
}
