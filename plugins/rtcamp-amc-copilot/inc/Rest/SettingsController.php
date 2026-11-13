<?php
/**
 * Route: /settings.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Rest;

use AMC\Core\Settings;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class SettingsController
 *
 * Owner: Wave 0 (implemented).
 *
 * GET  /amc/v1/settings  → Settings (Settings::for_rest(): password replaced
 *                          by loopback_auth.has_password, plus kill_switch_locked).
 * POST /amc/v1/settings  { ...partial settings } → Settings. Unknown keys ignored.
 *
 * @since 0.1.0
 */
final class SettingsController extends AbstractController {

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		$this->route( '/settings', self::readable(), [ $this, 'get_settings' ], [ $this, 'manage' ] );
		$this->route( '/settings', self::creatable(), [ $this, 'update_settings' ], [ $this, 'manage_settings' ] );
	}

	/**
	 * Saving settings needs amc_manage + manage_options (it can switch alerts off).
	 */
	public function manage_settings(): bool {
		return $this->manage() && current_user_can( 'manage_options' );
	}

	/**
	 * GET /settings.
	 */
	public function get_settings(): WP_REST_Response {
		return new WP_REST_Response( Settings::for_rest() );
	}

	/**
	 * POST /settings.
	 *
	 * @param WP_REST_Request $request Request (JSON body = partial settings).
	 */
	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$changes = $request->get_json_params();

		Settings::update( is_array( $changes ) ? $changes : [] );

		return new WP_REST_Response( Settings::for_rest() );
	}
}
