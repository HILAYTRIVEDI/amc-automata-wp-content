<?php
/**
 * Check: smoke.urls (server-side HTTP checks of key pages).
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks\Smoke;

use AMC\Checks\Check;
use AMC\Checks\Context;
use AMC\Checks\Finding;
use AMC\Checks\Group;
use AMC\Checks\Result;
use AMC\Checks\Schedule;
use AMC\Checks\Severity;
use AMC\Core\Settings;
use AMC\Support\SmokeRule;

/**
 * Class SmokeUrls
 *
 * Requests home, the login page, the latest post (or product, from the
 * `site_type` setting) and the engineer-configured URLs with wp_remote_get(),
 * and judges each response with SmokeRule (HTTP 200, under 3 s, no PHP error
 * text, optional required text).
 *
 * - `loopback_blocked` (DNS, refused, a 401 wall) is its own status. It is not
 *   a site failure: it neither raises a finding nor counts towards, or resets,
 *   the failure streak.
 * - A finding needs 2 failures IN A ROW for the same URL (the streak lives in
 *   `previous_data`); one failure raises nothing. A pass resets the streak.
 * - Basic-auth credentials from Settings are sent only to URLs on this
 *   site's own host, never to an external URL.
 * - Time budget: each request has a 4 s timeout and no new request starts
 *   once 16 s have passed. A pass visits every URL once; URLs not reached
 *   are checked first on the next run (`cursor`) and the run is returned
 *   partial, so nothing resolves while the pass is incomplete. With up to a
 *   handful of URLs a single run covers them all.
 *
 * Result::$data shape (Smoke screen, Performance screen, perf.response):
 *   checked_at  string  UTC ISO 8601.
 *   complete    bool    False when URLs were left for the next run.
 *   cursor      int     Index of the first URL the next run checks (0 when complete).
 *   counts      array{pass: int, fail: int, loopback_blocked: int, unchecked: int}
 *   urls        list<array{
 *     url: string, required_text: string, is_default: bool,
 *     status: 'pass'|'fail'|'loopback_blocked'|null,   null = never checked
 *     http_code: int|null, response_ms: int|null,
 *     reasons: list<string>,                           translated, empty on pass
 *     consecutive_failures: int,
 *     checked_at: string|null,
 *     history: list<array{at: string, ms: int, ok: bool}>,                       last 24 checks
 *     daily: array<string, array{n: int, ok: int, blocked: int, ms_sum: int, ms_n: int}>  last 35 days, 'Y-m-d' keys
 *   }>
 *
 * Status rules:
 *   any URL failed 2+ times in a row -> FAIL + one HIGH finding per URL
 *   else any URL failed once         -> WARN, no finding
 *   else any URL loopback blocked    -> INFO (nothing could be judged)
 *   else                             -> PASS
 *
 * Finding dedupe key: `smoke:<md5(url)>`.
 *
 * @since 0.1.0
 */
final class SmokeUrls implements Check {

	/**
	 * Per-request timeout in seconds.
	 */
	public const REQUEST_TIMEOUT = 4;

	/**
	 * No new request starts after this many seconds of the run.
	 */
	public const BUDGET_SECONDS = 16.0;

	/**
	 * Failures in a row before a finding is raised.
	 */
	public const FAILURES_FOR_FINDING = 2;

	/**
	 * Checks kept in `history`.
	 */
	private const HISTORY_LIMIT = 24;

	/**
	 * Days of `daily` buckets kept.
	 */
	private const DAILY_DAYS = 35;

	/**
	 * Largest response body read, in bytes.
	 */
	private const MAX_BODY_BYTES = 1048576;

	/**
	 * URLs to check: callable(): list<array{url: string, required_text: string, is_default: bool}>.
	 *
	 * @var callable
	 */
	private $urls_source;

	/**
	 * HTTP: callable( string $url, array{username: string, password: string}|null $auth ): array{code: int, body: string, seconds: float, error: string|null}.
	 *
	 * @var callable
	 */
	private $http;

	/**
	 * Clock: callable(): float (seconds, monotonic enough for a budget).
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Credentials: callable(): array{username: string, password: string, host: string}.
	 *
	 * @var callable
	 */
	private $auth_source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $urls_source URLs to check; default: home, login, latest post/product, Settings URLs.
	 * @param callable|null $http        HTTP transport; default wp_remote_get().
	 * @param callable|null $clock       Clock; default microtime( true ).
	 * @param callable|null $auth_source Basic-auth credentials and the host they may be sent to; default Settings.
	 */
	public function __construct( ?callable $urls_source = null, ?callable $http = null, ?callable $clock = null, ?callable $auth_source = null ) {
		$this->urls_source = $urls_source ?? [ self::class, 'default_urls' ];
		$this->http        = $http ?? [ self::class, 'request' ];
		$this->clock       = $clock ?? static fn (): float => microtime( true );
		$this->auth_source = $auth_source ?? [ self::class, 'default_auth' ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'smoke.urls';
	}

	/**
	 * {@inheritDoc}
	 */
	public function group(): Group {
		return Group::SMOKE;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Smoke checks', 'rtcamp-amc-copilot' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function schedule(): Schedule {
		return Schedule::HOURLY;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Context $ctx Run context.
	 */
	public function run( Context $ctx ): Result {
		$urls = $this->unique_urls( ( $this->urls_source )() );

		if ( [] === $urls ) {
			return Result::info(
				__( 'There are no URLs to check.', 'rtcamp-amc-copilot' ),
				[
					'checked_at' => gmdate( 'c', $ctx->now->getTimestamp() ),
					'complete'   => true,
					'cursor'     => 0,
					'counts'     => [
						'pass'             => 0,
						'fail'             => 0,
						'loopback_blocked' => 0,
						'unchecked'        => 0,
					],
					'urls'       => [],
				]
			);
		}

		$previous = [];
		foreach ( (array) ( $ctx->previous_data['urls'] ?? [] ) as $row ) {
			if ( is_array( $row ) && isset( $row['url'] ) ) {
				$previous[ (string) $row['url'] ] = $row;
			}
		}

		$total  = count( $urls );
		$cursor = $this->resume_cursor( $ctx, $total );
		$auth   = ( $this->auth_source )();
		$began  = ( $this->clock )();
		$states = [];

		foreach ( $urls as $index => $row ) {
			$states[ $index ] = $this->carry( $row, $previous[ $row['url'] ] ?? [] );
		}

		// One pass visits every URL once: it starts at index 0 and, when the budget runs out, resumes at the cursor.
		$checked = 0;
		for ( $index = $cursor; $index < $total; $index++ ) {
			if ( $checked > 0 && ( ( $this->clock )() - $began ) + self::REQUEST_TIMEOUT > self::BUDGET_SECONDS ) {
				break;
			}

			$states[ $index ] = $this->check_one( $states[ $index ], $ctx, $auth );
			++$checked;
		}

		$next     = $cursor + $checked;
		$complete = $next >= $total;
		$data     = [
			'checked_at' => gmdate( 'c', $ctx->now->getTimestamp() ),
			'complete'   => $complete,
			'cursor'     => $complete ? 0 : $next,
			'counts'     => [
				'pass'             => 0,
				'fail'             => 0,
				'loopback_blocked' => 0,
				'unchecked'        => 0,
			],
			'urls'       => array_values( $states ),
		];

		$findings = [];
		$streaks  = 0;
		$failed   = 0;

		foreach ( $data['urls'] as $state ) {
			$key                    = $state['status'] ?? 'unchecked';
			$data['counts'][ $key ] = ( $data['counts'][ $key ] ?? 0 ) + 1;

			if ( 'fail' === $state['status'] ) {
				++$failed;
			}

			if ( $state['consecutive_failures'] >= self::FAILURES_FOR_FINDING ) {
				++$streaks;
				$findings[] = new Finding(
					'smoke:' . md5( $state['url'] ),
					Severity::HIGH,
					sprintf(
						/* translators: 1: URL, 2: Number of failures in a row. */
						__( 'Smoke check failing: %1$s (%2$d failures in a row)', 'rtcamp-amc-copilot' ),
						$state['url'],
						$state['consecutive_failures']
					),
					[
						'url'                  => $state['url'],
						'http_code'            => $state['http_code'],
						'response_ms'          => $state['response_ms'],
						'reasons'              => $state['reasons'],
						'consecutive_failures' => $state['consecutive_failures'],
					]
				);
			}
		}

		$result = $this->result( $data, $findings, $streaks, $failed );

		return $complete ? $result : $result->partial();
	}

	/**
	 * Build the result for the counts.
	 *
	 * @param array<string, mixed>      $data     Data.
	 * @param list<\AMC\Checks\Finding> $findings Findings.
	 * @param int                       $streaks  URLs with a failing streak.
	 * @param int                       $failed   URLs that failed this run.
	 */
	private function result( array $data, array $findings, int $streaks, int $failed ): Result {
		$counts = $data['counts'];

		if ( $streaks > 0 ) {
			return Result::fail(
				sprintf(
					/* translators: %d: Number of URLs. */
					_n( '%d URL has failed twice in a row.', '%d URLs have failed twice in a row.', $streaks, 'rtcamp-amc-copilot' ),
					$streaks
				),
				$data,
				$findings
			);
		}

		if ( $failed > 0 ) {
			return Result::warn(
				sprintf(
					/* translators: %d: Number of URLs. */
					_n( '%d URL failed this run (one failure raises no alert).', '%d URLs failed this run (one failure raises no alert).', $failed, 'rtcamp-amc-copilot' ),
					$failed
				),
				$data
			);
		}

		if ( $counts['loopback_blocked'] > 0 ) {
			return Result::info(
				sprintf(
					/* translators: %d: Number of URLs. */
					_n( 'Loopback blocked: %d URL could not be reached from the server itself.', 'Loopback blocked: %d URLs could not be reached from the server itself.', $counts['loopback_blocked'], 'rtcamp-amc-copilot' ),
					$counts['loopback_blocked']
				),
				$data
			);
		}

		return Result::pass(
			sprintf(
				/* translators: %d: Number of URLs. */
				_n( '%d URL passed.', 'All %d URLs passed.', max( 1, $counts['pass'] ), 'rtcamp-amc-copilot' ),
				$counts['pass']
			),
			$data
		);
	}

	/**
	 * Where this run starts: the cursor the previous run left, when it was partial.
	 *
	 * @param Context $ctx   Run context.
	 * @param int     $total Number of URLs.
	 */
	private function resume_cursor( Context $ctx, int $total ): int {
		$previous = $ctx->previous_data ?? [];

		if ( false !== ( $previous['complete'] ?? true ) ) {
			return 0;
		}

		$cursor = (int) ( $previous['cursor'] ?? 0 );

		return $cursor >= 0 && $cursor < $total ? $cursor : 0;
	}

	/**
	 * Start a URL's state from its previous state (history carries over).
	 *
	 * @param array{url: string, required_text: string, is_default: bool} $row  URL row.
	 * @param array<string, mixed>                                        $last Previous state.
	 *
	 * @return array<string, mixed>
	 */
	private function carry( array $row, array $last ): array {
		return [
			'url'                  => $row['url'],
			'required_text'        => $row['required_text'],
			'is_default'           => $row['is_default'],
			'status'               => isset( $last['status'] ) && in_array( $last['status'], [ 'pass', 'fail', 'loopback_blocked' ], true ) ? (string) $last['status'] : null,
			'http_code'            => isset( $last['http_code'] ) ? (int) $last['http_code'] : null,
			'response_ms'          => isset( $last['response_ms'] ) ? (int) $last['response_ms'] : null,
			'reasons'              => array_values( array_map( 'strval', (array) ( $last['reasons'] ?? [] ) ) ),
			'consecutive_failures' => (int) ( $last['consecutive_failures'] ?? 0 ),
			'checked_at'           => isset( $last['checked_at'] ) ? (string) $last['checked_at'] : null,
			'history'              => array_values( (array) ( $last['history'] ?? [] ) ),
			'daily'                => (array) ( $last['daily'] ?? [] ),
		];
	}

	/**
	 * Request one URL and fold the verdict into its state.
	 *
	 * @param array<string, mixed>                                  $state State so far.
	 * @param Context                                               $ctx   Run context.
	 * @param array{username: string, password: string, host: string} $auth  Credentials.
	 *
	 * @return array<string, mixed>
	 */
	private function check_one( array $state, Context $ctx, array $auth ): array {
		$use_auth = '' !== $auth['username'] && '' !== $auth['password'] && '' !== $auth['host']
			&& strtolower( $auth['host'] ) === strtolower( (string) wp_parse_url( $state['url'], PHP_URL_HOST ) );

		$response = ( $this->http )(
			$state['url'],
			$use_auth ? [
				'username' => $auth['username'],
				'password' => $auth['password'],
			] : null
		);

		$verdict = SmokeRule::evaluate(
			$response['code'],
			$response['seconds'],
			$response['body'],
			'' === $state['required_text'] ? null : $state['required_text'],
			$response['error']
		);

		$ms = (int) round( $response['seconds'] * 1000 );
		$at = gmdate( 'c', $ctx->now->getTimestamp() );

		$state['status']      = $verdict['status'];
		$state['http_code']   = $response['code'] > 0 ? $response['code'] : null;
		$state['response_ms'] = $response['code'] > 0 ? $ms : null;
		$state['reasons']     = 'pass' === $verdict['status'] ? [] : $this->translate( $verdict['codes'], $response, $state );
		$state['checked_at']  = $at;

		if ( 'pass' === $verdict['status'] ) {
			$state['consecutive_failures'] = 0;
		} elseif ( 'fail' === $verdict['status'] ) {
			++$state['consecutive_failures'];
		} // loopback_blocked: the site could not be judged, so the streak is left alone.

		$state['history'][] = [
			'at' => $at,
			'ms' => $response['code'] > 0 ? $ms : 0,
			'ok' => 'pass' === $verdict['status'],
		];
		$state['history']   = array_slice( $state['history'], -self::HISTORY_LIMIT );

		$day                   = gmdate( 'Y-m-d', $ctx->now->getTimestamp() );
		$bucket                = $state['daily'][ $day ] ?? [
			'n'       => 0,
			'ok'      => 0,
			'blocked' => 0,
			'ms_sum'  => 0,
			'ms_n'    => 0,
		];
		$bucket['n']          += 1;
		$bucket['ok']         += 'pass' === $verdict['status'] ? 1 : 0;
		$bucket['blocked']    += 'loopback_blocked' === $verdict['status'] ? 1 : 0;
		if ( $response['code'] > 0 ) {
			$bucket['ms_sum'] += $ms;
			$bucket['ms_n']   += 1;
		}
		$state['daily'][ $day ] = $bucket;

		ksort( $state['daily'] );
		$state['daily'] = array_slice( $state['daily'], -self::DAILY_DAYS, null, true );

		return $state;
	}

	/**
	 * Translated reasons for SmokeRule's reason codes.
	 *
	 * @param list<string>                                                                      $codes    Reason codes.
	 * @param array{code: int, body: string, seconds: float, error: string|null}               $response Response.
	 * @param array<string, mixed>                                                              $state    URL state.
	 *
	 * @return list<string>
	 */
	private function translate( array $codes, array $response, array $state ): array {
		$reasons = [];

		foreach ( array_unique( $codes ) as $code ) {
			$reasons[] = match ( $code ) {
				'http_status'           => sprintf(
					/* translators: %d: HTTP status code. */
					__( 'HTTP status %d, expected 200.', 'rtcamp-amc-copilot' ),
					$response['code']
				),
				'too_slow'              => sprintf(
					/* translators: %s: Seconds. */
					__( 'The response took %s s, over the 3 s limit.', 'rtcamp-amc-copilot' ),
					number_format_i18n( $response['seconds'], 2 )
				),
				'php_error'             => __( 'PHP error text was found in the page.', 'rtcamp-amc-copilot' ),
				'required_text_missing' => sprintf(
					/* translators: %s: Required text. */
					__( 'The required text "%s" was not found.', 'rtcamp-amc-copilot' ),
					$state['required_text']
				),
				'auth_wall'             => __( 'HTTP 401: the site is behind basic auth or another access wall. Add credentials in the settings.', 'rtcamp-amc-copilot' ),
				'loopback'              => sprintf(
					/* translators: %s: Error message. */
					__( 'The server could not reach its own site (loopback blocked): %s', 'rtcamp-amc-copilot' ),
					(string) $response['error']
				),
				default                 => sprintf(
					/* translators: %s: Error message. */
					__( 'The request failed: %s', 'rtcamp-amc-copilot' ),
					(string) $response['error']
				),
			};
		}

		return $reasons;
	}

	/**
	 * Drop duplicate URLs and rows without a URL, keeping the first.
	 *
	 * @param mixed $rows Rows from the URL source.
	 *
	 * @return list<array{url: string, required_text: string, is_default: bool}>
	 */
	private function unique_urls( mixed $rows ): array {
		$seen = [];
		$out  = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$url = trim( (string) ( $row['url'] ?? '' ) );

			if ( '' === $url || isset( $seen[ $url ] ) ) {
				continue;
			}

			$seen[ $url ] = true;
			$out[]        = [
				'url'           => $url,
				'required_text' => (string) ( $row['required_text'] ?? '' ),
				'is_default'    => (bool) ( $row['is_default'] ?? false ),
			];
		}

		return $out;
	}

	/**
	 * Home, login, the latest post or product, and the URLs from Settings.
	 *
	 * @return list<array{url: string, required_text: string, is_default: bool}>
	 */
	public static function default_urls(): array {
		$urls = [
			[
				'url'           => home_url( '/' ),
				'required_text' => '',
				'is_default'    => true,
			],
			[
				'url'           => wp_login_url(),
				'required_text' => '',
				'is_default'    => true,
			],
		];

		$post_type = 'ecommerce' === Settings::get_value( 'site_type' ) ? 'product' : 'post';
		$latest    = get_posts(
			[
				'post_type'           => $post_type,
				'post_status'         => 'publish',
				'numberposts'         => 1,
				'orderby'             => 'date',
				'order'               => 'DESC',
				'fields'              => 'ids',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'suppress_filters'    => false,
			]
		);

		$permalink = isset( $latest[0] ) ? get_permalink( (int) $latest[0] ) : false;
		if ( is_string( $permalink ) && '' !== $permalink ) {
			$urls[] = [
				'url'           => $permalink,
				'required_text' => '',
				'is_default'    => true,
			];
		}

		foreach ( (array) Settings::get_value( 'smoke_urls' ) as $row ) {
			$urls[] = [
				'url'           => (string) ( $row['url'] ?? '' ),
				'required_text' => (string) ( $row['required_text'] ?? '' ),
				'is_default'    => false,
			];
		}

		return $urls;
	}

	/**
	 * Credentials from Settings and the host they are allowed to go to.
	 *
	 * @return array{username: string, password: string, host: string}
	 */
	public static function default_auth(): array {
		$auth = (array) Settings::get_value( 'loopback_auth' );

		return [
			'username' => (string) ( $auth['username'] ?? '' ),
			'password' => Settings::loopback_password(),
			'host'     => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
		];
	}

	/**
	 * The real HTTP transport.
	 *
	 * @param string                                         $url  URL.
	 * @param array{username: string, password: string}|null $auth Basic-auth credentials.
	 *
	 * @return array{code: int, body: string, seconds: float, error: string|null}
	 */
	public static function request( string $url, ?array $auth ): array {
		$headers = [];

		if ( null !== $auth ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( $auth['username'] . ':' . $auth['password'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth header.
		}

		$start    = microtime( true );
		$response = wp_remote_get( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- Smoke checks request the site itself with a short timeout.
			$url,
			[
				'timeout'             => self::REQUEST_TIMEOUT,
				'redirection'         => 3,
				'limit_response_size' => self::MAX_BODY_BYTES,
				// Only this site's own host may skip certificate checks (as for WordPress loopback requests); external URLs are always verified.
				'sslverify'           => strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) )
					? (bool) apply_filters( 'https_local_ssl_verify', false )
					: true,
				'headers'             => $headers,
				'user-agent'          => 'AMC-Copilot-Smoke/1.0',
			]
		);
		$seconds  = microtime( true ) - $start;

		if ( is_wp_error( $response ) ) {
			return [
				'code'    => 0,
				'body'    => '',
				'seconds' => $seconds,
				'error'   => $response->get_error_message(),
			];
		}

		return [
			'code'    => (int) wp_remote_retrieve_response_code( $response ),
			'body'    => (string) wp_remote_retrieve_body( $response ),
			'seconds' => $seconds,
			'error'   => null,
		];
	}
}
