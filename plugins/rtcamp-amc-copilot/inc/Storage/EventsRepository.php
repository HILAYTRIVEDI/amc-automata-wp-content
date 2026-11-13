<?php
/**
 * Repository for `amc_events` (hourly error/deprecation aggregates).
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Storage;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom table repository.

/**
 * Class EventsRepository
 *
 * One row per (blog_id, signature, hour). The capture layer (lane A1)
 * buffers events in memory and calls upsert_many() ONCE in `shutdown`; the
 * UNIQUE KEY blog_signature_hour turns repeats into `count = count + n`.
 *
 * Row shape: id int, blog_id int, type string, signature string (32-char
 * md5), message string, source string, file string, line int, hour string
 * (UTC, minutes/seconds zero), count int, last_seen string.
 *
 * `type` values: fatal, warning, notice, deprecation, doing_it_wrong.
 * `source` values: 'core', 'plugin:<slug>', 'theme:<slug>', 'mu-plugin:<file>', 'unknown'.
 * `signature`: md5( type . '|' . file . '|' . line . '|' . normalised message ),
 *   computed by the capture layer (A1). errors.* findings use it as dedupe_key.
 *
 * Owner: Wave 0 (schema + methods). A1 may ADD read methods it needs.
 *
 * @since 0.1.0
 */
final class EventsRepository extends AbstractRepository {

	protected const TABLE        = Tables::EVENTS;
	protected const INT_COLUMNS  = [ 'id', 'blog_id', 'line', 'count' ];
	protected const JSON_COLUMNS = [];

	/**
	 * Event types.
	 */
	public const TYPES = [ 'fatal', 'warning', 'notice', 'deprecation', 'doing_it_wrong' ];

	/**
	 * Truncate a timestamp to its UTC hour, the `hour` column format.
	 *
	 * @param int|null $timestamp Unix time (default now).
	 *
	 * @return string 'Y-m-d H:00:00'
	 */
	public static function hour( ?int $timestamp = null ): string {
		return gmdate( 'Y-m-d H:00:00', $timestamp ?? time() );
	}

	/**
	 * Insert or increment many aggregate rows in ONE query.
	 *
	 * @param list<array<string, mixed>> $rows Buffered events, already grouped by signature+hour.
	 *        Keys: type: string, signature: string, message?: string, source?: string, file?: string,
	 *        line?: int, hour?: string, count?: int, last_seen?: string, blog_id?: int.
	 */
	public function upsert_many( array $rows ): bool {
		global $wpdb;

		if ( [] === $rows ) {
			return true;
		}

		$values = [];
		foreach ( $rows as $row ) {
			$values[] = $wpdb->prepare(
				'(%d, %s, %s, %s, %s, %s, %d, %s, %d, %s)',
				$row['blog_id'] ?? get_current_blog_id(),
				$row['type'],
				$row['signature'],
				mb_substr( (string) ( $row['message'] ?? '' ), 0, 2000 ),
				mb_substr( (string) ( $row['source'] ?? '' ), 0, 191 ),
				mb_substr( (string) ( $row['file'] ?? '' ), 0, 255 ),
				(int) ( $row['line'] ?? 0 ),
				$row['hour'] ?? self::hour(),
				max( 1, (int) ( $row['count'] ?? 1 ) ),
				$row['last_seen'] ?? self::now()
			);
		}

		$sql = $wpdb->prepare( 'INSERT INTO %i (blog_id, type, signature, message, source, file, line, hour, count, last_seen) VALUES ', $this->table() )
			. implode( ', ', $values )
			. ' ON DUPLICATE KEY UPDATE count = count + VALUES(count), last_seen = GREATEST(last_seen, VALUES(last_seen))';

		return false !== $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Built only from prepare() fragments.
	}

	/**
	 * Events grouped by signature over a period (Errors screen, reports).
	 *
	 * @param array<string, mixed> $args since/until are UTC on `hour`.
	 *        Keys: blog_id?: int, type?: string, since?: string, until?: string, limit?: int.
	 *
	 * @return list<array{signature: string, type: string, message: string, source: string, file: string, line: int, count: int, first_seen: string, last_seen: string}>
	 */
	public function grouped( array $args = [] ): array {
		global $wpdb;

		$where = $wpdb->prepare( ' WHERE blog_id = %d', $this->blog_id( $args['blog_id'] ?? null ) );
		if ( ! empty( $args['type'] ) ) {
			$where .= $wpdb->prepare( ' AND type = %s', $args['type'] );
		}
		if ( ! empty( $args['since'] ) ) {
			$where .= $wpdb->prepare( ' AND hour >= %s', $args['since'] );
		}
		if ( ! empty( $args['until'] ) ) {
			$where .= $wpdb->prepare( ' AND hour < %s', $args['until'] );
		}

		$sql = $wpdb->prepare(
			'SELECT signature, MAX(type) AS type, MAX(message) AS message, MAX(source) AS source, MAX(file) AS file, MAX(line) AS line,
				SUM(count) AS count, MIN(hour) AS first_seen, MAX(last_seen) AS last_seen FROM %i',
			$this->table()
		) . $where . $wpdb->prepare( ' GROUP BY signature ORDER BY count DESC LIMIT %d', (int) ( $args['limit'] ?? 200 ) );

		return $this->select( $sql );
	}

	/**
	 * Total count per type over a period.
	 *
	 * @param string   $since   UTC start (inclusive).
	 * @param string   $until   UTC end (exclusive).
	 * @param int|null $blog_id Site.
	 *
	 * @return array<string, int> type => count (every type present).
	 */
	public function counts_by_type( string $since, string $until, ?int $blog_id = null ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT type, SUM(count) AS n FROM %i WHERE blog_id = %d AND hour >= %s AND hour < %s GROUP BY type',
				$this->table(),
				$this->blog_id( $blog_id ),
				$since,
				$until
			),
			ARRAY_A
		);

		$counts = array_fill_keys( self::TYPES, 0 );
		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['type'] ] = (int) $row['n'];
		}

		return $counts;
	}

	/**
	 * Daily totals of one signature (sparkline on the Errors screen).
	 *
	 * @param string   $signature Signature.
	 * @param int      $days      Days back from today (UTC).
	 * @param int|null $blog_id   Site.
	 *
	 * @return array<string, int> 'Y-m-d' => count, one entry per day, oldest first.
	 */
	public function daily_counts( string $signature, int $days = 30, ?int $blog_id = null ): array {
		global $wpdb;

		$start = gmdate( 'Y-m-d 00:00:00', time() - ( $days - 1 ) * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DATE(hour) AS day, SUM(count) AS n FROM %i WHERE blog_id = %d AND signature = %s AND hour >= %s GROUP BY DATE(hour)',
				$this->table(),
				$this->blog_id( $blog_id ),
				$signature,
				$start
			),
			ARRAY_A
		);

		$series = [];
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$series[ gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS ) ] = 0;
		}
		foreach ( (array) $rows as $row ) {
			$series[ (string) $row['day'] ] = (int) $row['n'];
		}

		return $series;
	}
}
