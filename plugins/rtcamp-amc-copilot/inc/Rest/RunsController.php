<?php
/**
 * Routes: /runs, /runs/{id}, /runs/{id}/rerun.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Rest;

use AMC\Checks\Registry;
use AMC\Runner\Runner;
use AMC\Storage\FindingsRepository;
use AMC\Storage\RunsRepository;
use AMC\Storage\Tables;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class RunsController
 *
 * Owner: Wave 0 (implemented).
 *
 * GET  /amc/v1/runs               Paginated runs (Runs screen).
 * GET  /amc/v1/runs/{id}          One run with data and findings (also the "Run now" poll).
 *                                  `?detail=1` adds what the Run detail page needs to
 *                                  debug it: user, schedule, queue_ms, older_id and
 *                                  newer_id (same check, any status), previous (the
 *                                  last finished result before it, with data) and
 *                                  history (the check's 10 newest runs, no data).
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

		$this->route(
			'/runs/(?P<id>\d+)',
			self::readable(),
			[ $this, 'get_run' ],
			[ $this, 'manage' ],
			$id_arg + [
				'detail' => [
					'type'    => 'boolean',
					'default' => false,
				],
			]
		);
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

		if ( true === $request->get_param( 'detail' ) ) {
			$run += self::detail( $row );
		}

		return new WP_REST_Response( $run );
	}

	/**
	 * Debugging context for one run (the Run detail page).
	 *
	 * @param array<string, mixed> $row RunsRepository row.
	 *
	 * @return array<string, mixed>
	 */
	private static function detail( array $row ): array {
		$runs     = new RunsRepository();
		$id       = (int) $row['id'];
		$check_id = (string) $row['check_id'];
		$blog_id  = (int) $row['blog_id'];
		$check    = ( new Registry() )->get( $check_id );
		$user     = (int) $row['user_id'] > 0 ? get_userdata( (int) $row['user_id'] ) : false;
		$previous = $runs->latest( $check_id, $blog_id, $id );
		$history  = $runs->query(
			[
				'blog_id'  => $blog_id,
				'check_id' => $check_id,
				'per_page' => 10,
			]
		);

		$created = self::to_timestamp( $row['created_at'] );
		$started = self::to_timestamp( $row['started_at'] );

		return [
			'user'     => $user instanceof \WP_User
				? [
					'id'   => (int) $user->ID,
					'name' => (string) $user->display_name,
				]
				: null,
			'schedule' => null !== $check ? Registry::effective_schedule( $check )->value : null,
			'queue_ms' => null !== $created && null !== $started ? max( 0, $started - $created ) * 1000 : null,
			'older_id' => self::neighbour( $blog_id, $check_id, $id, '<' ),
			'newer_id' => self::neighbour( $blog_id, $check_id, $id, '>' ),
			'previous' => null !== $previous ? self::format_run( $previous, true ) : null,
			'history'  => array_map( static fn ( array $r ): array => self::format_run( $r ), $history['items'] ),
		];
	}

	/**
	 * The closest older or newer run of the same check, any status.
	 *
	 * @param int    $blog_id  Site.
	 * @param string $check_id Check.
	 * @param int    $id       Run ID.
	 * @param string $dir      '<' for older, '>' for newer.
	 */
	private static function neighbour( int $blog_id, string $check_id, int $id, string $dir ): ?int {
		global $wpdb;

		$sql = '<' === $dir
			? 'SELECT id FROM %i WHERE blog_id = %d AND check_id = %s AND id < %d ORDER BY id DESC LIMIT 1'
			: 'SELECT id FROM %i WHERE blog_id = %d AND check_id = %s AND id > %d ORDER BY id ASC LIMIT 1';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One indexed lookup on the plugin's own table; $sql is one of two literals.
		$found = $wpdb->get_var( $wpdb->prepare( $sql, Tables::name( Tables::RUNS ), $blog_id, $check_id, $id ) );

		return null === $found ? null : (int) $found;
	}

	/**
	 * DB datetime (UTC) to a Unix timestamp, or null.
	 *
	 * @param mixed $value DB value.
	 */
	private static function to_timestamp( mixed $value ): ?int {
		$iso = self::iso( $value );

		return null === $iso ? null : (int) strtotime( $iso );
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
