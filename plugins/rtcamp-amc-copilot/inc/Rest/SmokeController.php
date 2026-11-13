<?php
/**
 * Route: /smoke.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Rest;

use AMC\Core\Settings;
use WP_REST_Response;

/**
 * Class SmokeController
 *
 * Owner: lane B2. STUB: returns the configured extra URLs with empty stats
 * and header `X-AMC-Stub: B2`. B2 replaces get_screen() once smoke.urls
 * stores per-URL results. Add URL / edit required text = POST /settings
 * { smoke_urls }. Run now = POST /checks/run { check_ids: ['smoke.urls'] }.
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
 *     pass_rate_30d: float|null,         0..1
 *     trend: list<{ at: string, ms: int, ok: bool }>   last 30 days
 *   }>
 * }
 *
 * @since 0.1.0
 */
final class SmokeController extends AbstractController {

	/**
	 * Owning lane.
	 */
	public const OWNER = 'B2';

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		$this->route( '/smoke', self::readable(), [ $this, 'get_screen' ], [ $this, 'manage' ] );
	}

	/**
	 * GET /smoke.
	 */
	public function get_screen(): WP_REST_Response {
		$urls = [];

		foreach ( (array) Settings::get_value( 'smoke_urls' ) as $row ) {
			$urls[] = [
				'url'              => (string) $row['url'],
				'required_text'    => (string) $row['required_text'],
				'is_default'       => false,
				'last_status'      => null,
				'last_http_code'   => null,
				'last_response_ms' => null,
				'pass_rate_30d'    => null,
				'trend'            => [],
			];
		}

		return $this->stub(
			[
				'last_run' => $this->latest_results( [ 'smoke.urls' ] )['smoke.urls'],
				'urls'     => $urls,
			],
			self::OWNER
		);
	}
}
