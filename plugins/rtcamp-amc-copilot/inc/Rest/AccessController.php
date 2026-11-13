<?php
/**
 * Routes: /access.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Rest;

use AMC\Core\Capabilities;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class AccessController
 *
 * Owner: Wave 0 (implemented). Settings screen → "Engineers with access".
 * All routes need amc_manage + manage_options.
 *
 * GET    /amc/v1/access             → list<{ id, login, display_name, email }>
 * POST   /amc/v1/access { user_id } → 201 Engineer
 * DELETE /amc/v1/access/{user_id}   → { revoked: true, user_id }
 *
 * @since 0.1.0
 */
final class AccessController extends AbstractController {

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		$user_arg = [
			'user_id' => [
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			],
		];

		$this->route( '/access', self::readable(), [ $this, 'list_engineers' ], [ $this, 'manage_access' ] );
		$this->route( '/access', self::creatable(), [ $this, 'grant' ], [ $this, 'manage_access' ], $user_arg );
		$this->route( '/access/(?P<user_id>\d+)', self::deletable(), [ $this, 'revoke' ], [ $this, 'manage_access' ], $user_arg );
	}

	/**
	 * GET /access.
	 */
	public function list_engineers(): WP_REST_Response {
		return new WP_REST_Response( array_map( [ self::class, 'format_user' ], Capabilities::engineers() ) );
	}

	/**
	 * POST /access.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function grant( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$user_id = (int) $request->get_param( 'user_id' );
		$result  = Capabilities::grant( $user_id, get_current_user_id(), 'admin' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( self::format_user( get_userdata( $user_id ) ), 201 );
	}

	/**
	 * DELETE /access/{user_id}.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function revoke( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$user_id = (int) $request->get_param( 'user_id' );

		if ( get_current_user_id() === $user_id ) {
			return new \WP_Error( 'amc_self_revoke', __( 'You cannot remove your own access.', 'rtcamp-amc-copilot' ), [ 'status' => 400 ] );
		}

		$result = Capabilities::revoke( $user_id, get_current_user_id(), 'admin' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			[
				'revoked' => true,
				'user_id' => $user_id,
			]
		);
	}

	/**
	 * Engineer shape.
	 *
	 * @param mixed $user WP_User.
	 *
	 * @return array{id: int, login: string, display_name: string, email: string}
	 */
	public static function format_user( mixed $user ): array {
		return [
			'id'           => (int) $user->ID,
			'login'        => (string) $user->user_login,
			'display_name' => (string) $user->display_name,
			'email'        => (string) $user->user_email,
		];
	}
}
