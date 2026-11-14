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
 *                         400 `amc_invalid_email` when `report_publish_recipients`
 *                         holds an invalid address (`data.invalid` lists them),
 *                         400 `amc_too_many_recipients` above the cap. Nothing
 *                         is saved on either error.
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
	public function update_settings( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$changes = $request->get_json_params();
		$changes = is_array( $changes ) ? $changes : [];

		if ( array_key_exists( 'report_publish_recipients', $changes ) ) {
			$error = self::validate_report_publish_recipients( $changes['report_publish_recipients'] );

			if ( null !== $error ) {
				return $error;
			}
		}

		Settings::update( $changes );

		return new WP_REST_Response( Settings::for_rest() );
	}

	/**
	 * Reject an invalid `report_publish_recipients` value instead of silently
	 * dropping addresses the user typed.
	 *
	 * @param mixed $value Array of addresses, or one string separated by new lines or commas.
	 */
	private static function validate_report_publish_recipients( mixed $value ): ?\WP_Error {
		if ( ! is_array( $value ) && ! is_string( $value ) ) {
			return new \WP_Error(
				'amc_invalid_email',
				__( 'Report recipients must be a list of email addresses.', 'rtcamp-amc-copilot' ),
				[
					'status'  => 400,
					'invalid' => [],
				]
			);
		}

		$parsed = Settings::parse_email_list( $value );

		if ( [] !== $parsed['invalid'] ) {
			return new \WP_Error(
				'amc_invalid_email',
				sprintf(
					/* translators: %s: comma-separated list of invalid entries. */
					_n( 'This is not a valid email address: %s', 'These are not valid email addresses: %s', count( $parsed['invalid'] ), 'rtcamp-amc-copilot' ),
					implode( ', ', $parsed['invalid'] )
				),
				[
					'status'  => 400,
					'invalid' => $parsed['invalid'],
				]
			);
		}

		if ( count( $parsed['valid'] ) > Settings::MAX_REPORT_PUBLISH_RECIPIENTS ) {
			return new \WP_Error(
				'amc_too_many_recipients',
				sprintf(
					/* translators: %d: maximum number of addresses. */
					__( 'Enter at most %d report recipients.', 'rtcamp-amc-copilot' ),
					Settings::MAX_REPORT_PUBLISH_RECIPIENTS
				),
				[ 'status' => 400 ]
			);
		}

		return null;
	}
}
