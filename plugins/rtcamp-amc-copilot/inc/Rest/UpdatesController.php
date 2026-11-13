<?php
/**
 * Route: /updates.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Rest;

use WP_REST_Response;

/**
 * Class UpdatesController
 *
 * Owner: lane B1. Wave 0 ships a working generic handler: the latest
 * finished run of each check below, as { run: Run, data: <check data> }
 * or null when the check has not run yet. The `data` shapes are defined
 * by each check's class docblock. Lane B1 may add computed keys; existing
 * keys must not change.
 *
 * GET /amc/v1/updates → {
 *   checks: { 'updates.core': { run, data } | null, 'updates.plugins': ..., 'updates.themes': ...,
 *             'updates.php': ..., 'updates.readiness': ... }
 * }
 *
 * @since 0.1.0
 */
final class UpdatesController extends AbstractController {

	public const OWNER = 'B1';

	/**
	 * Check ids shown on this screen.
	 */
	public const CHECK_IDS = [ 'updates.core', 'updates.plugins', 'updates.themes', 'updates.php', 'updates.readiness' ];

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		$this->route( '/updates', self::readable(), [ $this, 'get_screen' ], [ $this, 'manage' ] );
	}

	/**
	 * GET /updates.
	 */
	public function get_screen(): WP_REST_Response {
		return new WP_REST_Response( [ 'checks' => $this->latest_results( self::CHECK_IDS ) ] );
	}
}
