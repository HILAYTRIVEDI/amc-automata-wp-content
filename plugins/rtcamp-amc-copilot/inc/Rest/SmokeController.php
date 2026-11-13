<?php
/**
 * Route: /smoke.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Rest;

use AMC\Core\Settings;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class SmokeController
 *
 * Owner: lane B2.
 *
 * GET /amc/v1/smoke → {
 *   last_run: { run, data } | null,
 *   urls: list<{
 *     url: string,
 *     required_text: string,
 *     is_default: bool,                  home / login / latest post or product
 *     last_status: 'pass'|'fail'|'loopback_blocked'|null,
 *     last_http_code: int|null,
 *     last_response_ms: int|null,
 *     pass_rate_30d: float|null,         0..1; loopback-blocked checks are not counted
 *     trend: list<{ at: string, ms: int, ok: bool }>   one point per day, last 30 days, oldest first;
 *                                                      `ms` is the day's average, `ok` = no failure that day
 *   }>
 * }
 *
 * The URLs are the ones the latest smoke.urls run checked, plus any extra URL
 * in Settings it has not reached yet (empty stats).
 *
 * POST /amc/v1/smoke/urls { url: string (http or https), required_text?: string } → { urls: list<{ url, required_text }> }
 *   Adds an extra URL, or edits the required text of one that is already
 *   there (matched by URL). Engineers only (`amc_manage`), unlike
 *   POST /settings. The default URLs (home, login, latest post) are not
 *   stored and cannot be edited here. At most 25 extra URLs.
 *
 * Run now = POST /checks/run { check_ids: ['smoke.urls'] }.
 *
 * @since 0.1.0
 */
final class SmokeController extends AbstractController {

	/**
	 * Owning lane.
	 */
	public const OWNER = 'B2';

	/**
	 * Most extra URLs an engineer can add.
	 */
	public const MAX_URLS = 25;

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		$this->route( '/smoke', self::readable(), [ $this, 'get_screen' ], [ $this, 'manage' ] );

		$this->route(
			'/smoke/urls',
			self::creatable(),
			[ $this, 'save_url' ],
			[ $this, 'manage' ],
			[
				'url'           => [
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'esc_url_raw',
					'validate_callback' => static fn ( $value ): bool => 1 === preg_match( '#^https?://[^\s/$.?\#].[^\s]*$#i', (string) $value ),
				],
				'required_text' => [
					'type'              => 'string',
					'default'           => '',
					'maxLength'         => 200,
					'sanitize_callback' => 'sanitize_text_field',
				],
			]
		);
	}

	/**
	 * GET /smoke.
	 */
	public function get_screen(): WP_REST_Response {
		$last   = $this->latest_results( [ 'smoke.urls' ] )['smoke.urls'];
		$extras = [];
		foreach ( (array) Settings::get_value( 'smoke_urls' ) as $row ) {
			$extras[ (string) $row['url'] ] = (string) $row['required_text'];
		}

		$urls = [];
		foreach ( (array) ( $last['data']['urls'] ?? [] ) as $state ) {
			if ( ! is_array( $state ) || ! isset( $state['url'] ) ) {
				continue;
			}

			$url     = (string) $state['url'];
			$default = (bool) ( $state['is_default'] ?? false );

			$urls[] = self::format_url(
				$url,
				$default ? (string) ( $state['required_text'] ?? '' ) : ( $extras[ $url ] ?? (string) ( $state['required_text'] ?? '' ) ),
				$default,
				$state
			);
			unset( $extras[ $url ] );
		}

		foreach ( $extras as $url => $required_text ) {
			$urls[] = self::format_url( (string) $url, $required_text, false, [] );
		}

		return new WP_REST_Response(
			[
				'last_run' => $last,
				'urls'     => $urls,
			]
		);
	}

	/**
	 * POST /smoke/urls.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function save_url( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$url           = (string) $request->get_param( 'url' );
		$required_text = (string) $request->get_param( 'required_text' );
		$rows          = (array) Settings::get_value( 'smoke_urls' );
		$found         = false;

		foreach ( $rows as $index => $row ) {
			if ( (string) $row['url'] === $url ) {
				$rows[ $index ]['required_text'] = $required_text;
				$found                           = true;
			}
		}

		if ( ! $found ) {
			if ( count( $rows ) >= self::MAX_URLS ) {
				return new \WP_Error(
					'rest_invalid_param',
					/* translators: %d: Maximum number of URLs. */
					sprintf( __( 'You can add at most %d extra URLs.', 'rtcamp-amc-copilot' ), self::MAX_URLS ),
					[ 'status' => 400 ]
				);
			}

			$rows[] = [
				'url'           => $url,
				'required_text' => $required_text,
			];
		}

		Settings::update( [ 'smoke_urls' => array_values( $rows ) ] );

		return new WP_REST_Response( [ 'urls' => array_values( (array) Settings::get_value( 'smoke_urls' ) ) ] );
	}

	/**
	 * One URL of the screen from its latest state.
	 *
	 * @param string               $url           URL.
	 * @param string               $required_text Required text.
	 * @param bool                 $is_default    Whether it is a default URL.
	 * @param array<string, mixed> $state         State from the latest smoke.urls run (empty when none).
	 *
	 * @return array<string, mixed>
	 */
	private static function format_url( string $url, string $required_text, bool $is_default, array $state ): array {
		$since = gmdate( 'Y-m-d', time() - 29 * DAY_IN_SECONDS );
		$ok    = 0;
		$total = 0;
		$trend = [];

		foreach ( (array) ( $state['daily'] ?? [] ) as $date => $bucket ) {
			if ( (string) $date < $since || ! is_array( $bucket ) ) {
				continue;
			}

			$judged  = (int) ( $bucket['n'] ?? 0 ) - (int) ( $bucket['blocked'] ?? 0 );
			$ok     += (int) ( $bucket['ok'] ?? 0 );
			$total  += $judged;
			$trend[] = [
				'at' => (string) $date . 'T00:00:00Z',
				'ms' => (int) ( $bucket['ms_n'] ?? 0 ) > 0 ? (int) round( (int) $bucket['ms_sum'] / (int) $bucket['ms_n'] ) : 0,
				'ok' => (int) ( $bucket['ok'] ?? 0 ) >= $judged,
			];
		}

		return [
			'url'              => $url,
			'required_text'    => $required_text,
			'is_default'       => $is_default,
			'last_status'      => isset( $state['status'] ) && in_array( $state['status'], [ 'pass', 'fail', 'loopback_blocked' ], true ) ? (string) $state['status'] : null,
			'last_http_code'   => isset( $state['http_code'] ) ? (int) $state['http_code'] : null,
			'last_response_ms' => isset( $state['response_ms'] ) ? (int) $state['response_ms'] : null,
			'pass_rate_30d'    => $total > 0 ? round( $ok / $total, 4 ) : null,
			'trend'            => $trend,
		];
	}
}
