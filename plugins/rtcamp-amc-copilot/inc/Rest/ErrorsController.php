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
 * Owner: lane A1 (capture). Works today on whatever is in amc_events;
 * A1 may optimise the sparkline query. Mark resolved / mute =
 * POST /findings/{finding_id}/status.
 *
 * GET /amc/v1/errors?type=&days=30 → list<{
 *   signature, type, message, source, file, line, count,
 *   first_seen, last_seen,                 ISO 8601
 *   sparkline: list<int>,                  one count per day, oldest first, `days` long
 *   finding_id: int|null,                  unresolved errors.* finding for this signature
 *   finding_status: string|null
 * }>
 *
 * @since 0.1.0
 */
final class ErrorsController extends AbstractController {

	/**
	 * Owning lane.
	 */
	public const OWNER = 'A1';

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
			]
		);

		$findings = ( new FindingsRepository() )->query(
			[
				'check_ids' => [ 'errors.fatal', 'errors.warnings', 'errors.deprecations' ],
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
}
