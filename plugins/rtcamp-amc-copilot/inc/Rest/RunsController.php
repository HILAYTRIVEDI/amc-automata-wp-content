<?php
/**
 * Routes: /runs, /runs/{id}, /runs/{id}/rerun.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Rest;

use AMC\Runner\Runner;
use AMC\Storage\FindingsRepository;
use AMC\Storage\RunsRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class RunsController
 *
 * Owner: Wave 0 (implemented).
 *
 * GET  /amc/v1/runs               Paginated runs (Runs screen).
 * GET  /amc/v1/runs/{id}          One run with data and findings (also the "Run now" poll).
 * POST /amc/v1/runs/{id}/rerun    Queue the same check again.
 *
 * @since 0.1.0
 */
final class RunsController extends AbstractController {

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		$this->route(
			'/runs',
			self::readable(),
			[ $this, 'list_runs' ],
			[ $this, 'manage' ],
			array_merge(
				$this->pagination_args(),
				[
					'check_id' => [ 'type' => 'string' ],
					'status'   => [
						'type' => 'string',
						'enum' => RunsRepository::STATUSES,
					],
					'trigger'  => [
						'type' => 'string',
						'enum' => RunsRepository::TRIGGERS,
					],
					'since'    => [
						'type'        => 'string',
						'description' => 'ISO 8601, inclusive (created_at).',
					],
					'until'    => [
						'type'        => 'string',
						'description' => 'ISO 8601, exclusive (created_at).',
					],
				]
			)
		);

		$id_arg = [
			'id' => [
				'type'     => 'integer',
				'required' => true,
			],
		];

		$this->route( '/runs/(?P<id>\d+)', self::readable(), [ $this, 'get_run' ], [ $this, 'manage' ], $id_arg );
		$this->route( '/runs/(?P<id>\d+)/rerun', self::creatable(), [ $this, 'rerun' ], [ $this, 'manage' ], $id_arg );
	}

	/**
	 * GET /runs → list<Run> (without data).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list_runs( WP_REST_Request $request ): WP_REST_Response {
		$per_page = (int) $request->get_param( 'per_page' );
		$result   = ( new RunsRepository() )->query(
			[
				'check_id' => (string) $request->get_param( 'check_id' ),
				'status'   => (string) $request->get_param( 'status' ),
				'trigger'  => (string) $request->get_param( 'trigger' ),
				'since'    => (string) self::to_db_time( $request->get_param( 'since' ) ),
				'until'    => (string) self::to_db_time( $request->get_param( 'until' ) ),
				'page'     => (int) $request->get_param( 'page' ),
				'per_page' => $per_page,
			]
		);

		return $this->paginated(
			array_map( static fn ( array $row ): array => self::format_run( $row ), $result['items'] ),
			$result['total'],
			$per_page
		);
	}

	/**
	 * GET /runs/{id} → Run + { data, findings: list<Finding> }.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_run( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$row = ( new RunsRepository() )->find( (int) $request->get_param( 'id' ) );

		if ( null === $row || get_current_blog_id() !== (int) $row['blog_id'] ) {
			return new \WP_Error( 'amc_not_found', __( 'Run not found.', 'rtcamp-amc-copilot' ), [ 'status' => 404 ] );
		}

		$run             = self::format_run( $row, true );
		$findings        = ( new FindingsRepository() )->query(
			[
				'run_id'   => (int) $row['id'],
				'per_page' => 500,
			]
		);
		$run['findings'] = array_map( static fn ( array $f ): array => self::format_finding( $f ), $findings['items'] );

		return new WP_REST_Response( $run );
	}

	/**
	 * POST /runs/{id}/rerun → 202 { run_id }.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function rerun( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$row = ( new RunsRepository() )->find( (int) $request->get_param( 'id' ) );

		if ( null === $row || get_current_blog_id() !== (int) $row['blog_id'] ) {
			return new \WP_Error( 'amc_not_found', __( 'Run not found.', 'rtcamp-amc-copilot' ), [ 'status' => 404 ] );
		}

		$run_id = ( new Runner() )->enqueue( (string) $row['check_id'], get_current_user_id() );

		if ( is_wp_error( $run_id ) ) {
			return $run_id;
		}

		return new WP_REST_Response( [ 'run_id' => $run_id ], 202 );
	}
}
