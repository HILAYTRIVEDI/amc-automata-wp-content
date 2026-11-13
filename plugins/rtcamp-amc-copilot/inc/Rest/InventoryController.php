<?php
/**
 * Route: /inventory.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Rest;

use WP_REST_Response;

/**
 * Class InventoryController
 *
 * Owner: lane B1. Wave 0 ships a working generic handler: the latest
 * finished run of each check below, as { run: Run, data: <check data> }
 * or null when the check has not run yet. The `data` shapes are defined
 * by each check's class docblock. Lane B1 may add computed keys; existing
 * keys must not change.
 *
 * GET /amc/v1/inventory → {
 *   checks: { 'inventory.plugins': { run, data } | null, ... one key per id in CHECK_IDS }
 * }
 *
 * @since 0.1.0
 */
final class InventoryController extends AbstractController {

	public const OWNER = 'B1';

	/**
	 * Check ids shown on this screen.
	 */
	public const CHECK_IDS = [ 'inventory.plugins', 'inventory.themes', 'inventory.mu_dropins', 'inventory.editor', 'hosting.platform', 'detect.cdn', 'detect.forms', 'detect.mailer', 'detect.analytics', 'detect.accessibility' ];

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		$this->route( '/inventory', self::readable(), [ $this, 'get_screen' ], [ $this, 'manage' ] );
	}

	/**
	 * GET /inventory.
	 */
	public function get_screen(): WP_REST_Response {
		$checks = $this->latest_results( self::CHECK_IDS );

		$csv = [
			'columns' => [ 'file', 'name', 'version', 'active', 'network_active', 'wp_org', 'closed', 'abandoned', 'last_updated' ],
			'rows'    => [],
		];

		$plugins = $checks['inventory.plugins']['data']['plugins'] ?? [];
		foreach ( is_array( $plugins ) ? $plugins : [] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$csv['rows'][] = [
				(string) ( $row['file'] ?? '' ),
				(string) ( $row['name'] ?? '' ),
				(string) ( $row['version'] ?? '' ),
				! empty( $row['active'] ) ? 'yes' : 'no',
				! empty( $row['network_active'] ) ? 'yes' : 'no',
				! empty( $row['wp_org'] ) ? 'yes' : 'no',
				! empty( $row['closed'] ) ? 'yes' : 'no',
				! empty( $row['abandoned'] ) ? 'yes' : 'no',
				(string) ( $row['last_updated'] ?? '' ),
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
