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
 * Owner: lane B2.
 *
 * GET /amc/v1/vulnerabilities?status=unresolved|resolved|accepted|muted|open|all → {
 *   provider: { id: string, ready: bool, last_error: string|null },
 *   last_run: { run, data } | null,          latest vuln.match run
 *   findings: list<Finding>,                  data = VulnMatch::to_array() + installed_version
 *   counts: { open: { critical, high, medium, low, info }, accepted: int }   (added by B2)
 * }
 *
 * POST /amc/v1/vulnerabilities/{id}/accept { note: string (required) } → Finding
 *   Accept the risk of a vuln.match finding. The note is required (the reason
 *   is shown on the Vulnerabilities screen and in reports). 404 for a finding
 *   of another check. Undo with POST /findings/{id}/status { status: open }.
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

		$this->route(
			'/vulnerabilities/(?P<id>\d+)/accept',
			self::creatable(),
			[ $this, 'accept' ],
			[ $this, 'manage' ],
			[
				'note' => [
					'type'              => 'string',
					'required'          => true,
					'minLength'         => 1,
					'sanitize_callback' => 'sanitize_textarea_field',
					'validate_callback' => static fn ( $value ): bool => '' !== trim( (string) $value ),
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

		$open   = array_fill_keys( [ 'critical', 'high', 'medium', 'low', 'info' ], 0 );
		$counts = [
			'open'     => $open,
			'accepted' => 0,
		];
		foreach ( ( new FindingsRepository() )->query(
			[
				'check_id' => 'vuln.match',
				'status'   => 'unresolved',
				'per_page' => 500,
			]
		)['items'] as $row ) {
			if ( 'open' === $row['status'] && isset( $open[ $row['severity'] ] ) ) {
				++$counts['open'][ $row['severity'] ];
			} elseif ( 'accepted' === $row['status'] ) {
				++$counts['accepted'];
			}
		}

		return new WP_REST_Response(
			[
				'provider' => [
					'id'         => $provider->id(),
					'ready'      => $provider->is_ready(),
					'last_error' => $provider->last_error(),
				],
				'last_run' => $this->latest_results( [ 'vuln.match' ] )['vuln.match'],
				'findings' => array_map( static fn ( array $row ): array => self::format_finding( $row ), $findings['items'] ),
				'counts'   => $counts,
			]
		);
	}

	/**
	 * POST /vulnerabilities/{id}/accept.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function accept( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$repo = new FindingsRepository();
		$row  = $repo->find( (int) $request->get_param( 'id' ) );

		if ( null === $row || 'vuln.match' !== $row['check_id'] ) {
			return new \WP_Error( 'amc_not_found', __( 'Vulnerability finding not found.', 'rtcamp-amc-copilot' ), [ 'status' => 404 ] );
		}

		$repo->set_status( (int) $row['id'], 'accepted', (string) $request->get_param( 'note' ) );

		return new WP_REST_Response( self::format_finding( (array) $repo->find( (int) $row['id'] ) ) );
	}
}
