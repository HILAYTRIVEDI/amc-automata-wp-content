<?php
/**
 * Route: /security.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Rest;

use AMC\Storage\FindingsRepository;
use WP_REST_Response;

/**
 * Class SecurityController
 *
 * Owner: lane B2. Wave 0 ships a working generic handler: the latest
 * finished run of each check below, as { run: Run, data: <check data> }
 * or null when the check has not run yet. The `data` shapes are defined
 * by each check's class docblock. Lane B2 may add computed keys; existing
 * keys must not change.
 *
 * GET /amc/v1/security → {
 *   checks: { 'security.checksums': { run, data } | null, 'security.config': ..., 'security.admins': ...,
 *             'ssl.certificate': ... }
 * }
 *
 * @since 0.1.0
 */
final class SecurityController extends AbstractController {

	public const OWNER = 'B2';

	/**
	 * Check ids shown on this screen.
	 */
	public const CHECK_IDS = [ 'security.checksums', 'security.config', 'security.admins', 'ssl.certificate' ];

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		$this->route( '/security', self::readable(), [ $this, 'get_screen' ], [ $this, 'manage' ] );
	}

	/**
	 * GET /security.
	 */
	public function get_screen(): WP_REST_Response {
		$open = ( new FindingsRepository() )->query(
			[
				'check_ids' => self::CHECK_IDS,
				'status'    => 'unresolved',
				'per_page'  => 200,
			]
		);

		return new WP_REST_Response(
			[
				'checks'        => $this->latest_results( self::CHECK_IDS ),
				'open_findings' => array_map( static fn ( array $row ): array => self::format_finding( $row ), $open['items'] ),
			]
		);
	}
}
