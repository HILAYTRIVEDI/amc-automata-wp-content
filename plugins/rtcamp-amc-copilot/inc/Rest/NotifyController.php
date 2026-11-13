<?php
/**
 * Route: /notify/test.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Rest;

use AMC\Core\KillSwitch;
use AMC\Core\Settings;
use AMC\Notify\Digest;
use AMC\Notify\Mailer;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class NotifyController
 *
 * Owner: lane A2.
 *
 * POST /amc/v1/notify/test { type: 'immediate'|'digest' } → { sent: bool, recipients: list<string> }
 *   Sends a sample alert or digest to the effective recipients (Settings screen button).
 *
 * @since 0.1.0
 */
final class NotifyController extends AbstractController {

	/**
	 * Owning lane.
	 */
	public const OWNER = 'A2';

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		$this->route(
			'/notify/test',
			self::creatable(),
			[ $this, 'send_test' ],
			[ $this, 'manage' ],
			[
				'type' => [
					'type'    => 'string',
					'enum'    => [ 'immediate', 'digest' ],
					'default' => 'immediate',
				],
			]
		);
	}

	/**
	 * POST /notify/test: send a sample alert or digest to the effective recipients.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|\WP_Error `{ sent: bool, recipients: list<string> }`; 503 while the kill switch is on.
	 */
	public function send_test( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		if ( KillSwitch::is_active() ) {
			return new \WP_Error(
				'amc_disabled',
				__( 'AMC Copilot is switched off, so no email is sent.', 'rtcamp-amc-copilot' ),
				[ 'status' => 503 ]
			);
		}

		$recipients = Settings::recipients();
		$sent       = false;

		if ( [] !== $recipients ) {
			$sent = 'digest' === $request->get_param( 'type' )
				? Digest::send_sample()
				: Mailer::send_immediate(
					[
						[
							'id'       => 0,
							'check_id' => 'errors.fatal',
							'severity' => 'critical',
							'title'    => __( 'Test alert: a sample fatal error', 'rtcamp-amc-copilot' ),
						],
					],
					$recipients
				);
		}

		return new WP_REST_Response(
			[
				'sent'       => $sent,
				'recipients' => $recipients,
			]
		);
	}
}
