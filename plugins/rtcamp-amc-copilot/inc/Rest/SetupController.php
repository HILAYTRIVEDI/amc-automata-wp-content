<?php
/**
 * Routes: /setup, /setup/complete, /setup/dismiss.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Rest;

use AMC\Admin\SetupDefaults;
use AMC\Admin\SetupState;
use WP_REST_Response;

/**
 * Class SetupController
 *
 * The first-run setup wizard saves each step through the existing routes
 * (`/settings`, `/saved-fields`, `/checks/run`). Only its own state lives here.
 *
 * GET  /amc/v1/setup           { completed, completed_at|null, notice_dismissed,
 *                                detected: { environment, site_name, home_url, primary_domain,
 *                                admin_email, engineer_emails, host, ssl|null, suggestions } }
 * POST /amc/v1/setup/complete  Marks setup finished → same shape as GET, without `detected`.
 * POST /amc/v1/setup/dismiss   Hides the setup notice for the current user → same shape.
 *
 * All three need `amc_manage` + `manage_options`, like saving Settings.
 *
 * @since 0.1.0
 */
final class SetupController extends AbstractController {

	/**
	 * Owning lane.
	 */
	public const OWNER = 'C';

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		$this->route( '/setup', self::readable(), [ $this, 'get_setup' ], [ $this, 'manage_setup' ] );
		$this->route( '/setup/complete', self::creatable(), [ $this, 'complete' ], [ $this, 'manage_setup' ] );
		$this->route( '/setup/dismiss', self::creatable(), [ $this, 'dismiss' ], [ $this, 'manage_setup' ] );
	}

	/**
	 * Permission: may run the wizard.
	 */
	public function manage_setup(): bool {
		return $this->manage() && current_user_can( 'manage_options' );
	}

	/**
	 * GET /setup.
	 */
	public function get_setup(): WP_REST_Response {
		return new WP_REST_Response(
			array_merge( SetupState::for_rest(), [ 'detected' => ( new SetupDefaults() )->get() ] )
		);
	}

	/**
	 * POST /setup/complete.
	 */
	public function complete(): WP_REST_Response {
		SetupState::complete( get_current_user_id() );

		return new WP_REST_Response( SetupState::for_rest() );
	}

	/**
	 * POST /setup/dismiss.
	 */
	public function dismiss(): WP_REST_Response {
		SetupState::dismiss( get_current_user_id() );

		return new WP_REST_Response( SetupState::for_rest() );
	}
}
