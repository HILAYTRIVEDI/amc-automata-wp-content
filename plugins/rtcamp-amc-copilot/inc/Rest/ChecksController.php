<?php
/**
 * Routes: /checks, /checks/run.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Rest;

use AMC\Checks\Registry;
use AMC\Core\Settings;
use AMC\Runner\Runner;
use AMC\Runner\Scheduler;
use AMC\Storage\RunsRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class ChecksController
 *
 * Owner: Wave 0 (implemented).
 *
 * GET  /amc/v1/checks      List checks with schedule, enabled flag, last run, next run.
 * POST /amc/v1/checks/run  Queue checks ("Run now" / "Run all checks"). Never runs inline.
 *
 * @since 0.1.0
 */
final class ChecksController extends AbstractController {

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		$this->route( '/checks', self::readable(), [ $this, 'list_checks' ], [ $this, 'manage' ] );

		$this->route(
			'/checks/run',
			self::creatable(),
			[ $this, 'run_checks' ],
			[ $this, 'manage' ],
			[
				'check_ids' => [
					'description' => 'Check ids to queue, or [ "all" ].',
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'required'    => true,
					'minItems'    => 1,
				],
			]
		);
	}

	/**
	 * GET /checks → list<Check>.
	 *
	 * Check: { id, label, group, group_label, schedule, default_schedule,
	 *          enabled, last_run: Run|null, next_run_at: string|null }
	 */
	public function list_checks(): WP_REST_Response {
		$registry = new Registry();
		$runs     = new RunsRepository();
		$latest   = $runs->latest_per_check();
		$next     = Scheduler::next_runs( $registry->all(), $runs->last_run_times() );
		$items    = [];

		foreach ( $registry->all() as $id => $check ) {
			$items[] = [
				'id'               => $id,
				'label'            => $check->label(),
				'group'            => $check->group()->value,
				'group_label'      => $check->group()->label(),
				'schedule'         => Registry::effective_schedule( $check )->value,
				'default_schedule' => $check->schedule()->value,
				'enabled'          => Settings::check_enabled( $id ),
				'last_run'         => isset( $latest[ $id ] ) ? self::format_run( $latest[ $id ] ) : null,
				'next_run_at'      => null !== $next[ $id ] ? gmdate( 'Y-m-d\TH:i:s\Z', (int) $next[ $id ] ) : null,
			];
		}

		return new WP_REST_Response( $items );
	}

	/**
	 * POST /checks/run → 202 { runs: list<{ check_id, run_id }> }.
	 *
	 * Poll GET /runs/{run_id} until status is not queued/running.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function run_checks( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$registry = new Registry();
		$ids      = array_map( 'strval', (array) $request->get_param( 'check_ids' ) );

		if ( [ 'all' ] === $ids ) {
			$ids = array_keys( $registry->all() );
		}

		$runner = new Runner( $registry );
		$queued = [];

		foreach ( $ids as $check_id ) {
			$run_id = $runner->enqueue( $check_id, get_current_user_id() );

			if ( is_wp_error( $run_id ) ) {
				return $run_id;
			}

			$queued[] = [
				'check_id' => $check_id,
				'run_id'   => $run_id,
			];
		}

		return new WP_REST_Response( [ 'runs' => $queued ], 202 );
	}
}
