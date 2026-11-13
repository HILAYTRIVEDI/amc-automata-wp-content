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
		$checks = $this->latest_results( self::CHECK_IDS );

		$csv = [
			'columns' => [ 'check', 'item', 'installed', 'available', 'status' ],
			'rows'    => [],
		];

		$plugins = $checks['updates.plugins']['data']['plugins'] ?? [];
		foreach ( is_array( $plugins ) ? $plugins : [] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$csv['rows'][] = [
				'updates.plugins',
				(string) ( $row['name'] ?? $row['file'] ?? '' ),
				(string) ( $row['version'] ?? '' ),
				(string) ( $row['new_version'] ?? '' ),
				! empty( $row['update_available'] ) ? 'update available' : 'up to date',
			];
		}

		$themes = $checks['updates.themes']['data']['themes'] ?? [];
		foreach ( is_array( $themes ) ? $themes : [] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$csv['rows'][] = [
				'updates.themes',
				(string) ( $row['name'] ?? $row['stylesheet'] ?? '' ),
				(string) ( $row['version'] ?? '' ),
				(string) ( $row['new_version'] ?? '' ),
				! empty( $row['update_available'] ) ? 'update available' : 'up to date',
			];
		}

		return new WP_REST_Response(
			[
				'checks' => $checks,
				'csv'    => $csv,
			]
		);
	}
}
