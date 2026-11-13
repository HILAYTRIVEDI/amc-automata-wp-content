<?php
/**
 * Base class for the custom-table repositories.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Storage;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom tables; this layer IS the data access. Reads run in admin/REST/cron/CLI only.

/**
 * Class AbstractRepository
 *
 * Rules for every repository (and for any code that touches the tables):
 *
 * 1. Only repositories run SQL against AMC tables. Other code calls
 *    repository methods. Need a new query? Add a method to the repository
 *    owned by your lane (see docs/CONTRACTS.md § Who may edit what).
 * 2. Every SQL string is built ONLY from $wpdb->prepare() fragments. Table
 *    names go through the %i placeholder.
 * 3. Times are UTC 'Y-m-d H:i:s' strings; use static::now().
 * 4. Rows are returned as associative arrays. `id`, `blog_id` and other
 *    integer columns are cast to int; `data` is decoded to an array.
 * 5. `blog_id` defaults to get_current_blog_id(). The runner has already
 *    called switch_to_blog() when it matters, so this is the site being checked.
 *
 * @since 0.1.0
 */
abstract class AbstractRepository {

	/**
	 * Unprefixed table name, one of the Tables constants.
	 */
	protected const TABLE = '';

	/**
	 * Columns cast to int on read.
	 */
	protected const INT_COLUMNS = [ 'id', 'blog_id' ];

	/**
	 * Columns holding JSON, decoded on read and encoded on write.
	 */
	protected const JSON_COLUMNS = [ 'data' ];

	/**
	 * Full (prefixed) table name.
	 */
	public function table(): string {
		return Tables::name( static::TABLE );
	}

	/**
	 * Current UTC time in MySQL format.
	 *
	 * @param int|null $timestamp Unix timestamp (default: now).
	 *
	 * @return string 'Y-m-d H:i:s'
	 */
	public static function now( ?int $timestamp = null ): string {
		return gmdate( 'Y-m-d H:i:s', $timestamp ?? time() );
	}

	/**
	 * Resolve a blog ID argument (null = current site).
	 *
	 * @param int|null $blog_id Blog ID or null.
	 */
	protected function blog_id( ?int $blog_id ): int {
		return $blog_id ?? get_current_blog_id();
	}

	/**
	 * Insert one row. JSON columns are encoded; `blog_id` defaults to the
	 * current site.
	 *
	 * @param array<string, mixed> $row Column => value.
	 *
	 * @return int New row ID (0 on failure).
	 */
	public function insert_row( array $row ): int {
		global $wpdb;

		if ( ! isset( $row['blog_id'] ) ) {
			$row['blog_id'] = get_current_blog_id();
		}

		$row = $this->encode( $row );

		$ok = $wpdb->insert( $this->table(), $row );

		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Fetch one row by primary key `id`.
	 *
	 * @param int $id Row ID.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table(), $id ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Delete rows whose date column is older than a cut-off, in one batch.
	 *
	 * Used by the Retention job (lane A1): call repeatedly until it returns
	 * less than $limit, within the job's time budget.
	 *
	 * @param string $column  Date column (must be indexed).
	 * @param string $cutoff  UTC 'Y-m-d H:i:s'; rows strictly older are deleted.
	 * @param int    $limit   Batch size.
	 *
	 * @return int Rows deleted.
	 */
	public function delete_older_than( string $column, string $cutoff, int $limit = 1000 ): int {
		global $wpdb;

		$deleted = $wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE %i < %s LIMIT %d', $this->table(), $column, $cutoff, $limit )
		);

		return (int) $deleted;
	}

	/**
	 * Run a SELECT built from prepare() fragments and hydrate every row.
	 *
	 * @param string $sql Fully prepared SQL.
	 *
	 * @return list<array<string, mixed>>
	 */
	protected function select( string $sql ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Built only from $wpdb->prepare() fragments by the caller.

		return array_map( [ $this, 'hydrate' ], is_array( $rows ) ? $rows : [] );
	}

	/**
	 * Run a COUNT/aggregate query built from prepare() fragments.
	 *
	 * @param string $sql Fully prepared SQL.
	 */
	protected function count( string $sql ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Built only from $wpdb->prepare() fragments by the caller.
	}

	/**
	 * LIMIT/OFFSET clause for 1-based pagination.
	 *
	 * @param int $page     Page number (>= 1).
	 * @param int $per_page Rows per page (1..500).
	 *
	 * @return string Prepared ' LIMIT x OFFSET y'.
	 */
	protected function paginate( int $page, int $per_page ): string {
		global $wpdb;

		$per_page = max( 1, min( 500, $per_page ) );
		$page     = max( 1, $page );

		return $wpdb->prepare( ' LIMIT %d OFFSET %d', $per_page, ( $page - 1 ) * $per_page );
	}

	/**
	 * Cast integer columns and decode JSON columns of a DB row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 *
	 * @return array<string, mixed>
	 */
	protected function hydrate( array $row ): array {
		foreach ( static::INT_COLUMNS as $column ) {
			if ( array_key_exists( $column, $row ) && null !== $row[ $column ] ) {
				$row[ $column ] = (int) $row[ $column ];
			}
		}

		foreach ( static::JSON_COLUMNS as $column ) {
			if ( array_key_exists( $column, $row ) ) {
				$decoded        = is_string( $row[ $column ] ) ? json_decode( $row[ $column ], true ) : null;
				$row[ $column ] = is_array( $decoded ) ? $decoded : [];
			}
		}

		return $row;
	}

	/**
	 * Encode JSON columns of a row about to be written.
	 *
	 * @param array<string, mixed> $row Row.
	 *
	 * @return array<string, mixed>
	 */
	protected function encode( array $row ): array {
		foreach ( static::JSON_COLUMNS as $column ) {
			if ( array_key_exists( $column, $row ) && ! is_string( $row[ $column ] ) ) {
				$row[ $column ] = (string) wp_json_encode( $row[ $column ] ?? [] );
			}
		}

		return $row;
	}
}
