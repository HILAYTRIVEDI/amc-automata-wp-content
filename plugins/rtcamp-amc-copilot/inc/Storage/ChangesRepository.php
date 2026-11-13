<?php
/**
 * Repository for `amc_changes` (change log).
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Storage;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom table repository.

/**
 * Class ChangesRepository
 *
 * Row shape: id int, blog_id int, type string, action string, item string
 * (slug, theme stylesheet, 'WordPress' or user login), item_name string
 * (display name, kept so removed plugins still have a name), old_version
 * string, new_version string, source string, user_id int, created_at string.
 *
 * Writers: ChangeLog listeners and the deploy diff (lane A1), access
 * changes (Core\Capabilities), users.changes (lane B1).
 *
 * @since 0.1.0
 */
final class ChangesRepository extends AbstractRepository {

	protected const TABLE        = Tables::CHANGES;
	protected const INT_COLUMNS  = [ 'id', 'blog_id', 'user_id' ];
	protected const JSON_COLUMNS = [];

	/**
	 * Allowed `type` values.
	 */
	public const TYPES = [ 'plugin', 'theme', 'core', 'user' ];

	/**
	 * Allowed `action` values. access_grant/access_revoke = amc_manage changes.
	 */
	public const ACTIONS = [ 'install', 'update', 'activate', 'deactivate', 'delete', 'role', 'access_grant', 'access_revoke' ];

	/**
	 * Allowed `source` values.
	 */
	public const SOURCES = [ 'admin', 'cli', 'deploy', 'auto' ];

	/**
	 * Insert one change.
	 *
	 * @param array<string, mixed> $change Change row.
	 *        Keys: type: string, action: string, item: string, item_name?: string,
	 *        old_version?: string, new_version?: string, source?: string, user_id?: int,
	 *        created_at?: string, blog_id?: int.
	 *
	 * @return int New ID.
	 */
	public function insert( array $change ): int {
		return $this->insert_row(
			[
				'blog_id'     => $this->blog_id( $change['blog_id'] ?? null ),
				'type'        => $change['type'],
				'action'      => $change['action'],
				'item'        => mb_substr( $change['item'], 0, 191 ),
				'item_name'   => mb_substr( (string) ( $change['item_name'] ?? '' ), 0, 255 ),
				'old_version' => (string) ( $change['old_version'] ?? '' ),
				'new_version' => (string) ( $change['new_version'] ?? '' ),
				'source'      => in_array( $change['source'] ?? 'admin', self::SOURCES, true ) ? ( $change['source'] ?? 'admin' ) : 'admin',
				'user_id'     => (int) ( $change['user_id'] ?? get_current_user_id() ),
				'created_at'  => $change['created_at'] ?? self::now(),
			]
		);
	}

	/**
	 * Filtered, paginated change log (newest first).
	 *
	 * @param array<string, mixed> $args since/until are UTC on created_at.
	 *        Keys: blog_id?: int, type?: string, action?: string, source?: string, item?: string,
	 *        since?: string, until?: string, page?: int, per_page?: int.
	 *
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function query( array $args = [] ): array {
		global $wpdb;

		$where = $wpdb->prepare( ' WHERE blog_id = %d', $this->blog_id( $args['blog_id'] ?? null ) );

		foreach ( [ 'type', 'action', 'source', 'item' ] as $column ) {
			if ( ! empty( $args[ $column ] ) ) {
				$where .= $wpdb->prepare( ' AND %i = %s', $column, $args[ $column ] );
			}
		}
		if ( ! empty( $args['since'] ) ) {
			$where .= $wpdb->prepare( ' AND created_at >= %s', $args['since'] );
		}
		if ( ! empty( $args['until'] ) ) {
			$where .= $wpdb->prepare( ' AND created_at < %s', $args['until'] );
		}

		$from = $wpdb->prepare( 'FROM %i', $this->table() );

		return [
			'items' => $this->select( "SELECT * {$from}{$where} ORDER BY created_at DESC, id DESC" . $this->paginate( (int) ( $args['page'] ?? 1 ), (int) ( $args['per_page'] ?? 50 ) ) ),
			'total' => $this->count( "SELECT COUNT(*) {$from}{$where}" ),
		];
	}
}
