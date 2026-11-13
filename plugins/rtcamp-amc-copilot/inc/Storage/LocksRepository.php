<?php
/**
 * Repository for `amc_locks`.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Storage;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom table repository.

/**
 * Class LocksRepository
 *
 * Low-level lock rows. Use AMC\Runner\Lock instead of calling this directly.
 *
 * @since 0.1.0
 */
final class LocksRepository extends AbstractRepository {

	protected const TABLE        = Tables::LOCKS;
	protected const INT_COLUMNS  = [];
	protected const JSON_COLUMNS = [];

	/**
	 * Try to take a lock. Expired locks are taken over.
	 *
	 * @param string $name    Lock name (<= 191 chars).
	 * @param string $owner   Unique owner token.
	 * @param int    $seconds Time to live.
	 *
	 * @return bool True when acquired.
	 */
	public function acquire( string $name, string $owner, int $seconds ): bool {
		global $wpdb;

		// Clear an expired holder of this lock first.
		$wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE name = %s AND expires_at < %s', $this->table(), $name, self::now() )
		);

		// INSERT IGNORE affects 0 rows when another owner holds the lock.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (name, owner, expires_at) VALUES (%s, %s, %s)',
				$this->table(),
				$name,
				$owner,
				self::now( time() + $seconds )
			)
		);

		return 1 === (int) $inserted;
	}

	/**
	 * Release a lock held by $owner.
	 *
	 * @param string $name  Lock name.
	 * @param string $owner Owner token.
	 *
	 * @return bool True if a row was removed.
	 */
	public function release( string $name, string $owner ): bool {
		global $wpdb;

		return 1 === (int) $wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE name = %s AND owner = %s', $this->table(), $name, $owner )
		);
	}

	/**
	 * Whether a live (unexpired) lock exists.
	 *
	 * @param string $name Lock name.
	 */
	public function is_locked( string $name ): bool {
		global $wpdb;

		return $this->count(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE name = %s AND expires_at >= %s', $this->table(), $name, self::now() )
		) > 0;
	}
}
