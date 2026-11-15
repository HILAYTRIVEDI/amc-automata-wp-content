<?php
/**
 * Routes: /network/sites, /network/run.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Rest;

use AMC\Checks\Registry;
use AMC\Runner\Runner;
use AMC\Storage\FindingsRepository;
use AMC\Storage\RunsRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class NetworkController
 *
 * Multisite Network Admin endpoints:
 *   GET  /amc/v1/network/sites     List network subsites with aggregate health facts.
 *   POST /amc/v1/network/run       Enqueue a check run on a subsite or across all subsites.
 *
 * @since 0.1.0
 */
final class NetworkController extends AbstractController {

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		$this->route(
			'/network/sites',
			self::readable(),
			[ $this, 'list_sites' ],
			[ $this, 'manage_network' ],
			$this->pagination_args()
		);

		$this->route(
			'/network/run',
			self::creatable(),
			[ $this, 'queue_run' ],
			[ $this, 'manage_network' ],
			[
				'check_id' => [
					'type'     => 'string',
					'required' => true,
				],
				'blog_id'  => [
					'type'     => 'integer',
					'required' => false,
					'default'  => 0,
				],
			]
		);
	}

	/**
	 * Permission check: user can manage AMC Copilot and this is a multisite network.
	 */
	public function manage_network(): bool {
		return is_multisite() && $this->manage();
	}

	/**
	 * GET /amc/v1/network/sites → Paginated list of network subsites with health info.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list_sites( WP_REST_Request $request ): WP_REST_Response {
		$page     = (int) $request->get_param( 'page' ) ?: 1;
		$per_page = (int) $request->get_param( 'per_page' ) ?: 50;
		$offset   = ( $page - 1 ) * $per_page;

		$sites = get_sites(
			[
				'number' => $per_page,
				'offset' => $offset,
			]
		);

		$total = (int) get_sites( [ 'count' => true ] );

		$findings_repo = new FindingsRepository();
		$runs_repo     = new RunsRepository();
		$items         = [];

		foreach ( $sites as $site ) {
			$blog_id        = (int) $site->blog_id;
			$counts         = $findings_repo->open_counts_by_severity( $blog_id );
			$latest_runs    = $runs_repo->query(
				[
					'blog_id'  => $blog_id,
					'per_page' => 1,
				]
			);
			$latest_run     = $latest_runs['items'][0] ?? null;
			$overall_status = 'pass';

			if ( $counts['critical'] > 0 || ( $latest_run && 'fail' === $latest_run['status'] ) ) {
				$overall_status = 'fail';
			} elseif ( $counts['high'] > 0 || ( $latest_run && 'warn' === $latest_run['status'] ) ) {
				$overall_status = 'warn';
			} elseif ( null === $latest_run ) {
				$overall_status = 'unknown';
			}

			$items[] = [
				'id'         => $blog_id,
				'domain'     => (string) $site->domain,
				'path'       => (string) $site->path,
				'name'       => (string) get_blog_option( $blog_id, 'blogname', $site->domain ),
				'site_url'   => get_site_url( $blog_id ),
				'admin_url'  => get_admin_url( $blog_id, 'admin.php?page=amc-copilot' ),
				'status'     => $overall_status,
				'findings'   => $counts,
				'latest_run' => null !== $latest_run ? self::format_run( $latest_run ) : null,
			];
		}

		return $this->paginated( $items, $total, $per_page );
	}

	/**
	 * POST /amc/v1/network/run → Enqueue a check run on one or all subsites.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function queue_run( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$check_id = (string) $request->get_param( 'check_id' );
		$blog_id  = (int) $request->get_param( 'blog_id' );
		$registry = new Registry();

		if ( ! $registry->has( $check_id ) ) {
			return new \WP_Error(
				'amc_unknown_check',
				sprintf(
					/* translators: %s: Check id. */
					__( 'Unknown check "%s".', 'rtcamp-amc-copilot' ),
					$check_id
				),
				[ 'status' => 404 ]
			);
		}

		$user_id = get_current_user_id();
		$runner  = new Runner( $registry );
		$queued  = [];

		if ( $blog_id > 0 ) {
			$run_id = $runner->enqueue( $check_id, $user_id, $blog_id );
			if ( is_wp_error( $run_id ) ) {
				return $run_id;
			}
			$queued[ $blog_id ] = $run_id;
		} else {
			$site_ids = get_sites(
				[
					'fields' => 'ids',
					'number' => 200,
				]
			);
			foreach ( $site_ids as $site_id ) {
				$run_id = $runner->enqueue( $check_id, $user_id, (int) $site_id );
				if ( ! is_wp_error( $run_id ) ) {
					$queued[ (int) $site_id ] = $run_id;
				}
			}
		}

		return new WP_REST_Response(
			[
				'check_id' => $check_id,
				'queued'   => $queued,
				'count'    => count( $queued ),
			],
			202
		);
	}
}
