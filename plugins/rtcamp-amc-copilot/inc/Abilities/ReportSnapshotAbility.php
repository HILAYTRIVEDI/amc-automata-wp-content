<?php
/**
 * Ability: the snapshot behind one report.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Abilities;

use AMC\Core\Capabilities;
use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractAbility;
use WP_REST_Request;

/**
 * Class ReportSnapshotAbility
 *
 * `amc-copilot/report-snapshot`: read-only. Returns `{ id, taken_at, snapshot }`
 * for a report through `GET /amc/v1/reports/{id}/snapshot`.
 *
 * @since 0.1.0
 */
final class ReportSnapshotAbility extends AbstractAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return AbilitiesRegistrar::CATEGORY . '/report-snapshot';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function label(): string {
		return __( 'Get a report snapshot', 'rtcamp-amc-copilot' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return __( 'Returns the frozen data snapshot (check results for the period) that a maintenance report was generated from. Read-only.', 'rtcamp-amc-copilot' );
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
			'type'                 => 'object',
			'properties'           => [
				'id' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Report post ID.',
				],
			],
			'required'             => [ 'id' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'       => [ 'type' => 'integer' ],
				'taken_at' => [ 'type' => [ 'string', 'null' ] ],
				'snapshot' => [ 'type' => 'object' ],
			],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param mixed $input { id }.
	 */
	public function execute( mixed $input ): mixed {
		$id       = is_array( $input ) ? (int) ( $input['id'] ?? 0 ) : 0;
		$response = rest_do_request( new WP_REST_Request( 'GET', '/amc/v1/reports/' . $id . '/snapshot' ) );

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
