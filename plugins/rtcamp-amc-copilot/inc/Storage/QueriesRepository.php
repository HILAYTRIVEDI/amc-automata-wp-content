<?php
/**
 * Repository for `amc_queries` (hourly slow-query aggregates).
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Storage;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom table repository.

/**
 * Class QueriesRepository
 *
 * One row per (blog_id, signature, hour). The query sampler (lane A1) calls
 * upsert_many() once in `shutdown` on sampled requests only.
 *
 * Row shape: id int, blog_id int, signature string (md5 of the normalised
 * SQL), sql_sample string, caller string, hour string, count int,
 * max_ms float, avg_ms float.
 *
 * Retention is 90 days (not 13 months).
 *
 * @since 0.1.0
 */
final class QueriesRepository extends AbstractRepository {

	protected const TABLE        = Tables::QUERIES;
	protected const INT_COLUMNS  = [ 'id', 'blog_id', 'count' ];
	protected const JSON_COLUMNS = [];

	/**
	 * Retention in days.
	 */
	public const RETENTION_DAYS = 90;

	/**
	 * Insert or merge many aggregate rows in ONE query. avg_ms is merged as a
	 * weighted average, max_ms as the maximum.
	 *
	 * @param list<array<string, mixed>> $rows Buffered samples, grouped by signature+hour.
	 *        Keys: signature: string, sql_sample?: string, caller?: string, hour?: string,
	 *        count?: int, max_ms: float, avg_ms: float, blog_id?: int.
	 */
	public function upsert_many( array $rows ): bool {
		global $wpdb;

		if ( [] === $rows ) {
			return true;
		}

		$values = [];
		foreach ( $rows as $row ) {
			$values[] = $wpdb->prepare(
				'(%d, %s, %s, %s, %s, %d, %f, %f)',
				$row['blog_id'] ?? get_current_blog_id(),
				$row['signature'],
				mb_substr( (string) ( $row['sql_sample'] ?? '' ), 0, 2000 ),
				mb_substr( (string) ( $row['caller'] ?? '' ), 0, 255 ),
				$row['hour'] ?? EventsRepository::hour(),
				max( 1, (int) ( $row['count'] ?? 1 ) ),
				(float) $row['max_ms'],
				(float) $row['avg_ms']
			);
		}

		// Order matters: avg_ms uses the OLD count, so it is assigned before count.
		$sql = $wpdb->prepare( 'INSERT INTO %i (blog_id, signature, sql_sample, caller, hour, count, max_ms, avg_ms) VALUES ', $this->table() )
			. implode( ', ', $values )
			. ' ON DUPLICATE KEY UPDATE avg_ms = ((avg_ms * count) + (VALUES(avg_ms) * VALUES(count))) / (count + VALUES(count)),'
			. ' max_ms = GREATEST(max_ms, VALUES(max_ms)), count = count + VALUES(count)';

		return false !== $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Built only from prepare() fragments.
	}

	/**
	 * Slow queries grouped by caller+signature over a period.
	 *
	 * @param string   $since   UTC start (inclusive).
	 * @param int|null $blog_id Site.
	 * @param int      $limit   Max rows.
	 *
	 * @return list<array{signature: string, sql_sample: string, caller: string, count: int, max_ms: float, avg_ms: float, last_seen: string}>
	 */
	public function grouped( string $since, ?int $blog_id = null, int $limit = 100 ): array {
		global $wpdb;

		return $this->select(
			$wpdb->prepare(
				'SELECT signature, MAX(sql_sample) AS sql_sample, MAX(caller) AS caller, SUM(count) AS count, MAX(max_ms) AS max_ms,
					SUM(avg_ms * count) / SUM(count) AS avg_ms, MAX(hour) AS last_seen
				FROM %i WHERE blog_id = %d AND hour >= %s GROUP BY signature ORDER BY count DESC LIMIT %d',
				$this->table(),
				$this->blog_id( $blog_id ),
				$since,
				$limit
			)
		);
	}
}
