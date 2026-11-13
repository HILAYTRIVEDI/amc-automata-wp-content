<?php
/**
 * Route: /vulnerabilities.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Rest;

use AMC\Storage\FindingsRepository;
use AMC\Vulnerability\Providers;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class VulnerabilitiesController
 *
 * Owner: lane B2. Works today (provider info + vuln.match findings); B2 may
 * add keys. Accept risk = POST /findings/{id}/status { status: accepted, note }.
 *
 * GET /amc/v1/vulnerabilities?status=unresolved|resolved|accepted|open → {
 *   provider: { id: string, ready: bool, last_error: string|null },
 *   last_run: { run, data } | null,          latest vuln.match run
 *   findings: list<Finding>                   data = VulnMatch::to_array() + installed_version
 * }
 *
 * @since 0.1.0
 */
final class VulnerabilitiesController extends AbstractController {

	/**
	 * Owning lane.
	 */
	public const OWNER = 'B2';

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		$this->route(
			'/vulnerabilities',
			self::readable(),
			[ $this, 'get_screen' ],
			[ $this, 'manage' ],
			[
				'status' => [
					'type'    => 'string',
					'enum'    => array_merge( FindingsRepository::STATUSES, [ 'unresolved', 'all' ] ),
					'default' => 'all',
				],
			]
		);
	}

	/**
	 * GET /vulnerabilities.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_screen( WP_REST_Request $request ): WP_REST_Response {
		$provider = Providers::current();
		$status   = (string) $request->get_param( 'status' );
		$findings = ( new FindingsRepository() )->query(
			[
				'check_id' => 'vuln.match',
				'status'   => 'all' === $status ? '' : $status,
				'per_page' => 500,
			]
		);

		return new WP_REST_Response(
			[
				'provider' => [
					'id'         => $provider->id(),
					'ready'      => $provider->is_ready(),
					'last_error' => $provider->last_error(),
				],
				'last_run' => $this->latest_results( [ 'vuln.match' ] )['vuln.match'],
				'findings' => array_map( static fn ( array $row ): array => self::format_finding( $row ), $findings['items'] ),
			]
		);
	}
}
