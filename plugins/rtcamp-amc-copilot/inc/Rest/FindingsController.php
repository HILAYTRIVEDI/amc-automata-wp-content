<?php
/**
 * Routes: /findings, /findings/{id}, /findings/{id}/status.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Rest;

use AMC\Checks\Group;
use AMC\Checks\Registry;
use AMC\Storage\FindingsRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class FindingsController
 *
 * Owner: Wave 0 (implemented).
 *
 * GET  /amc/v1/findings               Filtered, paginated findings.
 * GET  /amc/v1/findings/{id}          One finding.
 * POST /amc/v1/findings/{id}/status   { status: open|accepted|muted|resolved, note? }
 *                                     Accept risk (Vulnerabilities), mute or
 *                                     mark resolved (Errors), reopen.
 *
 * @since 0.1.0
 */
final class FindingsController extends AbstractController {

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		$this->route(
			'/findings',
			self::readable(),
			[ $this, 'list_findings' ],
			[ $this, 'manage' ],
			array_merge(
				$this->pagination_args(),
				[
					'check_id' => [ 'type' => 'string' ],
					'group'    => [
						'type' => 'string',
						'enum' => array_column( Group::cases(), 'value' ),
					],
					'severity' => [
						'type' => 'string',
						'enum' => [ 'info', 'low', 'medium', 'high', 'critical' ],
					],
					'status'   => [
						'type'        => 'string',
						'enum'        => array_merge( FindingsRepository::STATUSES, [ 'unresolved' ] ),
						'description' => 'unresolved = open + accepted + muted.',
					],
					'since'    => [
						'type'        => 'string',
						'description' => 'ISO 8601; filters first_seen.',
					],
				]
			)
		);

		$this->route(
			'/findings/(?P<id>\d+)',
			self::readable(),
			[ $this, 'get_finding' ],
			[ $this, 'manage' ],
			[
				'id' => [
					'type'     => 'integer',
					'required' => true,
				],
			]
		);

		$this->route(
			'/findings/(?P<id>\d+)/status',
			self::creatable(),
			[ $this, 'set_status' ],
			[ $this, 'manage' ],
			[
				'id'     => [
					'type'     => 'integer',
					'required' => true,
				],
				'status' => [
					'type'     => 'string',
					'enum'     => FindingsRepository::STATUSES,
					'required' => true,
				],
				'note'   => [
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
				],
			]
		);
	}

	/**
	 * GET /findings → list<Finding>.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list_findings( WP_REST_Request $request ): WP_REST_Response {
		$per_page = (int) $request->get_param( 'per_page' );
		$args     = [
			'check_id' => (string) $request->get_param( 'check_id' ),
			'severity' => (string) $request->get_param( 'severity' ),
			'status'   => (string) $request->get_param( 'status' ),
			'since'    => (string) self::to_db_time( $request->get_param( 'since' ) ),
			'page'     => (int) $request->get_param( 'page' ),
			'per_page' => $per_page,
		];

		$group = Group::tryFrom( (string) $request->get_param( 'group' ) );
		if ( null !== $group ) {
			$args['check_ids'] = array_keys( ( new Registry() )->in_group( $group ) );
			if ( [] === $args['check_ids'] ) {
				return $this->paginated( [], 0, $per_page );
			}
		}

		$result = ( new FindingsRepository() )->query( $args );

		return $this->paginated(
			array_map( static fn ( array $row ): array => self::format_finding( $row ), $result['items'] ),
			$result['total'],
			$per_page
		);
	}

	/**
	 * GET /findings/{id} → Finding.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_finding( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$row = $this->find_for_site( (int) $request->get_param( 'id' ) );

		return is_wp_error( $row ) ? $row : new WP_REST_Response( self::format_finding( $row ) );
	}

	/**
	 * POST /findings/{id}/status → Finding.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function set_status( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$id  = (int) $request->get_param( 'id' );
		$row = $this->find_for_site( $id );

		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$repo = new FindingsRepository();
		$note = $request->has_param( 'note' ) ? (string) $request->get_param( 'note' ) : null;
		$repo->set_status( $id, (string) $request->get_param( 'status' ), $note );

		return new WP_REST_Response( self::format_finding( (array) $repo->find( $id ) ) );
	}

	/**
	 * Load a finding of the current site.
	 *
	 * @param int $id Finding ID.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function find_for_site( int $id ): array|\WP_Error {
		$row = ( new FindingsRepository() )->find( $id );

		if ( null === $row || get_current_blog_id() !== (int) $row['blog_id'] ) {
			return new \WP_Error( 'amc_not_found', __( 'Finding not found.', 'rtcamp-amc-copilot' ), [ 'status' => 404 ] );
		}

		return $row;
	}
}
