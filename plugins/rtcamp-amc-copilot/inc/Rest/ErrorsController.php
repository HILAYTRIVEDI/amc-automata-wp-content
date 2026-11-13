<?php
/**
 * Route: /errors.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Rest;

use AMC\Storage\EventsRepository;
use AMC\Storage\FindingsRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class ErrorsController
 *
 * Owner: lane A1 (capture). Reads amc_events.
 *
 * GET /amc/v1/errors?type=&days=30 → list<{
 *   signature, type, message, source, file, line, count,
 *   first_seen, last_seen,                 ISO 8601
 *   sparkline: list<int>,                  one count per day, oldest first, `days` long
 *   finding_id: int|null,                  unresolved errors.* finding for this signature
 *   finding_status: string|null
 * }>
 *
 * The list holds the LIMIT most frequent signatures of the period, because the
 * sparkline costs one query per signature (a batched repository method is a
 * contract change request).
 *
 * POST /amc/v1/errors/{signature}/status  { status: 'open'|'muted'|'resolved' } → Finding
 *   Applies POST /findings/{id}/status to the unresolved errors.* finding whose
 *   dedupe_key is the signature (also reachable through `finding_id`). 404
 *   `amc_not_found` until an errors.* check has opened a finding for it.
 *   A resolved signature that keeps occurring is reopened by the check as a
 *   NEW finding, as for every other check.
 *
 * @since 0.1.0
 */
final class ErrorsController extends AbstractController {

	/**
	 * Owning lane.
	 */
	public const OWNER = 'A1';

	/**
	 * Most frequent signatures returned.
	 */
	public const LIMIT = 100;

	/**
	 * Checks whose findings use an event signature as dedupe key.
	 */
	private const CHECKS = [ 'errors.fatal', 'errors.warnings', 'errors.deprecations' ];

	/**
	 * Statuses the status route accepts.
	 */
	private const STATUSES = [ 'open', 'muted', 'resolved' ];

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		$this->route(
			'/errors',
			self::readable(),
			[ $this, 'list_errors' ],
			[ $this, 'manage' ],
			[
				'type' => [
					'type' => 'string',
					'enum' => EventsRepository::TYPES,
				],
				'days' => [
					'type'    => 'integer',
					'default' => 30,
					'minimum' => 1,
					'maximum' => 90,
				],
			]
		);

		$this->route(
			'/errors/(?P<signature>[a-f0-9]{32})/status',
			self::creatable(),
			[ $this, 'set_status' ],
			[ $this, 'manage' ],
			[
				'signature' => [
					'type'     => 'string',
					'pattern'  => '^[a-f0-9]{32}$',
					'required' => true,
				],
				'status'    => [
					'type'     => 'string',
					'enum'     => self::STATUSES,
					'required' => true,
				],
			]
		);
	}

	/**
	 * GET /errors.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list_errors( WP_REST_Request $request ): WP_REST_Response {
		$days   = (int) $request->get_param( 'days' );
		$events = new EventsRepository();
		$rows   = $events->grouped(
			[
				'type'  => (string) $request->get_param( 'type' ),
				'since' => gmdate( 'Y-m-d H:00:00', time() - $days * DAY_IN_SECONDS ),
				'limit' => self::LIMIT,
			]
		);

		$findings = ( new FindingsRepository() )->query(
			[
				'check_ids' => self::CHECKS,
				'status'    => 'unresolved',
				'per_page'  => 500,
			]
		);
		$by_key   = array_column( $findings['items'], null, 'dedupe_key' );

		$items = [];
		foreach ( $rows as $row ) {
			$finding = $by_key[ $row['signature'] ] ?? null;
			$items[] = [
				'signature'      => (string) $row['signature'],
				'type'           => (string) $row['type'],
				'message'        => (string) $row['message'],
				'source'         => (string) $row['source'],
				'file'           => (string) $row['file'],
				'line'           => (int) $row['line'],
				'count'          => (int) $row['count'],
				'first_seen'     => self::iso( $row['first_seen'] ),
				'last_seen'      => self::iso( $row['last_seen'] ),
				'sparkline'      => array_values( $events->daily_counts( (string) $row['signature'], $days ) ),
				'finding_id'     => null !== $finding ? (int) $finding['id'] : null,
				'finding_status' => null !== $finding ? (string) $finding['status'] : null,
			];
		}

		return $this->paginated( $items, count( $items ), max( 1, count( $items ) ) );
	}

	/**
	 * POST /errors/{signature}/status → Finding.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function set_status( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$signature = (string) $request->get_param( 'signature' );
		$repo      = new FindingsRepository();
		$matches   = array_filter(
			$repo->query(
				[
					'check_ids' => self::CHECKS,
					'status'    => 'unresolved',
					'per_page'  => 500,
				]
			)['items'],
			static fn ( array $row ): bool => $signature === $row['dedupe_key']
		);

		if ( [] === $matches ) {
			return new \WP_Error( 'amc_not_found', __( 'No open finding for this error yet.', 'rtcamp-amc-copilot' ), [ 'status' => 404 ] );
		}

		$status = (string) $request->get_param( 'status' );
		foreach ( $matches as $row ) {
			$repo->set_status( (int) $row['id'], $status );
		}

		return new WP_REST_Response( self::format_finding( (array) $repo->find( (int) reset( $matches )['id'] ) ) );
	}
}
