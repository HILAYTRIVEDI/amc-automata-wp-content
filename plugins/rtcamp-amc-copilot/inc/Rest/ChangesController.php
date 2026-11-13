<?php
/**
 * Route: /changes.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Rest;

use AMC\Storage\ChangesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class ChangesController
 *
 * Owner: lane A1 (change log). Works today on whatever is in amc_changes.
 *
 * GET /amc/v1/changes?type=&action=&source=&since=&until=&page=&per_page= → list<{
 *   id, type, action, item, item_name, old_version, new_version, source,
 *   user_id, user_display_name: string|null, created_at
 * }>
 *
 * @since 0.1.0
 */
final class ChangesController extends AbstractController {

	/**
	 * Owning lane.
	 */
	public const OWNER = 'A1';

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		$this->route(
			'/changes',
			self::readable(),
			[ $this, 'list_changes' ],
			[ $this, 'manage' ],
			array_merge(
				$this->pagination_args(),
				[
					'type'   => [
						'type' => 'string',
						'enum' => ChangesRepository::TYPES,
					],
					'action' => [
						'type' => 'string',
						'enum' => ChangesRepository::ACTIONS,
					],
					'source' => [
						'type' => 'string',
						'enum' => ChangesRepository::SOURCES,
					],
					'since'  => [ 'type' => 'string' ],
					'until'  => [ 'type' => 'string' ],
				]
			)
		);
	}

	/**
	 * GET /changes.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list_changes( WP_REST_Request $request ): WP_REST_Response {
		$per_page = (int) $request->get_param( 'per_page' );
		$result   = ( new ChangesRepository() )->query(
			[
				'type'     => (string) $request->get_param( 'type' ),
				'action'   => (string) $request->get_param( 'action' ),
				'source'   => (string) $request->get_param( 'source' ),
				'since'    => (string) self::to_db_time( $request->get_param( 'since' ) ),
				'until'    => (string) self::to_db_time( $request->get_param( 'until' ) ),
				'page'     => (int) $request->get_param( 'page' ),
				'per_page' => $per_page,
			]
		);

		$items = array_map(
			static function ( array $row ): array {
				$user                     = $row['user_id'] > 0 ? get_userdata( (int) $row['user_id'] ) : false;
				$row['user_display_name'] = $user instanceof \WP_User ? $user->display_name : null;
				$row['created_at']        = self::iso( $row['created_at'] );
				unset( $row['blog_id'] );
				return $row;
			},
			$result['items']
		);

		return $this->paginated( $items, $result['total'], $per_page );
	}
}
