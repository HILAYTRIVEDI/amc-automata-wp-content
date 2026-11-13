<?php
/**
 * Example REST API controller.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Modules\REST;

use WP_REST_Response;
use WP_REST_Server;
use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractRESTController;

/**
 * Class - ExampleRESTController
 *
 * Registers routes under: /wp-json/amc-automata-features-features/v1/examples
 */
final class ExampleRESTController extends AbstractRESTController {

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected $namespace = 'amc-automata-features-features';

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected $rest_base = 'examples';

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace . '/v' . $this->version,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'get_items_permissions_check' ],
				],
			]
		);
	}

	/**
	 * Retrieves a collection of example items.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 */
	public function get_items( $request ): WP_REST_Response { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Required by parent signature.
		$response = [
			'example_response' => 'example_response',
		];

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return bool|\WP_Error True if the request has permission, WP_Error otherwise.
	 */
	public function get_items_permissions_check( $request ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Required by parent signature.
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new \WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to access this endpoint.', 'amc-automata-features-features' ),
			[ 'status' => rest_authorization_required_code() ]
		);
	}
}
