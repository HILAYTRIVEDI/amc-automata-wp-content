<?php
/**
 * Route: /notify/test.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Rest;

/**
 * Class NotifyController
 *
 * Owner: lane A2. STUB (501).
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
	 * POST /notify/test.
	 */
	public function send_test(): \WP_Error {
		return $this->not_implemented( self::OWNER );
	}
}
