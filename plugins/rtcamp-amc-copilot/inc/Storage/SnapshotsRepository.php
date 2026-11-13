<?php
/**
 * Repository for `amc_snapshots`.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Storage;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom table repository.

/**
 * Class SnapshotsRepository
 *
 * Inventory snapshots used for diffs (deploy detection, role/capability
 * changes, administrator changes).
 *
 * Row shape: id int, blog_id int, kind string, data array, taken_at string.
 *
 * `kind` values in Phase 1 (add yours here and in docs/CONTRACTS.md):
 *   'plugins'  (inventory.plugins, B1; diffed by A1 for deploy changes)
 *   'themes'   (inventory.themes, B1)
 *   'roles'    (users.roles, B1)
 *   'admins'   (security.admins, B2)
 *
 * @since 0.1.0
 */
final class SnapshotsRepository extends AbstractRepository {

	protected const TABLE = Tables::SNAPSHOTS;

	/**
	 * Store a snapshot.
	 *
	 * @param string               $kind    Snapshot kind.
	 * @param array<string, mixed> $data    Data (JSON).
	 * @param int|null             $blog_id Site.
	 *
	 * @return int New ID.
	 */
	public function insert( string $kind, array $data, ?int $blog_id = null ): int {
		return $this->insert_row(
			[
				'blog_id'  => $this->blog_id( $blog_id ),
				'kind'     => $kind,
				'data'     => $data,
				'taken_at' => self::now(),
			]
		);
	}

	/**
	 * Latest snapshot of a kind, optionally taken before a time.
	 *
	 * @param string      $kind    Snapshot kind.
	 * @param int|null    $blog_id Site.
	 * @param string|null $before  UTC 'Y-m-d H:i:s' (exclusive) or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public function latest( string $kind, ?int $blog_id = null, ?string $before = null ): ?array {
		global $wpdb;

		$sql = $wpdb->prepare( 'SELECT * FROM %i WHERE blog_id = %d AND kind = %s', $this->table(), $this->blog_id( $blog_id ), $kind );
		if ( null !== $before ) {
			$sql .= $wpdb->prepare( ' AND taken_at < %s', $before );
		}

		$rows = $this->select( $sql . ' ORDER BY taken_at DESC, id DESC LIMIT 1' );

		return $rows[0] ?? null;
	}
}
