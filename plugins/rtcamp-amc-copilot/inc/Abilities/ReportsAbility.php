<?php
/**
 * Ability: list maintenance reports.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Abilities;

use AMC\Core\Capabilities;
use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractAbility;
use WP_REST_Request;

/**
 * Class ReportsAbility
 *
 * `amc-copilot/reports`: read-only list of reports, through the same REST
 * route the console uses (`GET /amc/v1/reports`), so it follows whatever the
 * Reports lane returns for the current user.
 *
 * @since 0.1.0
 */
final class ReportsAbility extends AbstractAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return AbilitiesRegistrar::CATEGORY . '/reports';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function label(): string {
		return __( 'List maintenance reports', 'rtcamp-amc-copilot' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return __( 'Lists the AMC Copilot maintenance reports (id, title, status, period, edit and view links). Read-only.', 'rtcamp-amc-copilot' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function category(): string {
		return AbilitiesRegistrar::CATEGORY;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'status'   => [
					'type'    => 'string',
					'enum'    => [ 'any', 'draft', 'publish' ],
					'default' => 'any',
				],
				'page'     => [
					'type'    => 'integer',
					'minimum' => 1,
					'default' => 1,
				],
				'per_page' => [
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 20,
				],
			],
		];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function output_schema(): array {
		return [
			'type'  => 'array',
			'items' => [ 'type' => 'object' ],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param mixed $input { status?, page?, per_page? }.
	 */
	public function execute( mixed $input ): mixed {
		$input   = is_array( $input ) ? $input : [];
		$request = new WP_REST_Request( 'GET', '/amc/v1/reports' );
		$request->set_query_params(
			[
				'status'   => (string) ( $input['status'] ?? 'any' ),
				'page'     => (int) ( $input['page'] ?? 1 ),
				'per_page' => (int) ( $input['per_page'] ?? 20 ),
			]
		);

		$response = rest_do_request( $request );

		return $response->is_error() ? $response->as_error() : $response->get_data();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param mixed $input Unused.
	 */
	protected function permission( mixed $input = null ): bool|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Required by AbstractAbility.
		return current_user_can( Capabilities::MANAGE );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function meta(): array {
		return ReadOnlyMeta::get();
	}
}
