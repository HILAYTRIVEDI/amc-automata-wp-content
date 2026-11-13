<?php
/**
 * Repository for `amc_findings`.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Storage;

use AMC\Checks\Finding;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom table repository.

/**
 * Class FindingsRepository
 *
 * Row shape:
 *   id int, blog_id int, check_id string, dedupe_key string,
 *   severity 'info'|'low'|'medium'|'high'|'critical', title string,
 *   data array, status 'open'|'accepted'|'muted'|'resolved', note ?string,
 *   run_id int (last run that saw it), first_seen string, last_seen string,
 *   resolved_at ?string, alerted_at ?string.
 *
 * A finding row lives from first appearance until it resolves. If the same
 * dedupe_key comes back later, a NEW row is created, so alert throttling
 * ("alert once; alert again only if it resolves and comes back") is just
 * "alerted_at IS NULL".
 *
 * `accepted` (accepted risk) and `muted` findings stay unresolved and keep
 * updating last_seen, but are excluded from open counts and alerts.
 *
 * Only the Runner creates/updates/resolves findings. The console changes
 * status via set_status(); Notify (A2) calls mark_alerted().
 *
 * @since 0.1.0
 */
final class FindingsRepository extends AbstractRepository {

	protected const TABLE       = Tables::FINDINGS;
	protected const INT_COLUMNS = [ 'id', 'blog_id', 'run_id' ];

	public const STATUS_OPEN     = 'open';
	public const STATUS_ACCEPTED = 'accepted';
	public const STATUS_MUTED    = 'muted';
	public const STATUS_RESOLVED = 'resolved';

	/**
	 * Every finding status.
	 */
	public const STATUSES = [ 'open', 'accepted', 'muted', 'resolved' ];

	/**
	 * Unresolved findings of one check, keyed by dedupe_key.
	 *
	 * @param string   $check_id Check id.
	 * @param int|null $blog_id  Site.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function unresolved_for_check( string $check_id, ?int $blog_id = null ): array {
		global $wpdb;

		$rows = $this->select(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE blog_id = %d AND check_id = %s AND resolved_at IS NULL',
				$this->table(),
				$this->blog_id( $blog_id ),
				$check_id
			)
		);

		$by_key = [];
		foreach ( $rows as $row ) {
			$by_key[ $row['dedupe_key'] ] = $row;
		}

		return $by_key;
	}

	/**
	 * Insert a new finding.
	 *
	 * @param Finding  $finding  Finding from a Result.
	 * @param string   $check_id Check id.
	 * @param int      $run_id   Run that produced it.
	 * @param int|null $blog_id  Site.
	 *
	 * @return int New finding ID.
	 */
	public function insert_finding( Finding $finding, string $check_id, int $run_id, ?int $blog_id = null ): int {
		$now = self::now();

		return $this->insert_row(
			[
				'blog_id'    => $this->blog_id( $blog_id ),
				'check_id'   => $check_id,
				'dedupe_key' => $finding->dedupe_key,
				'severity'   => $finding->severity->value,
				'title'      => mb_substr( $finding->title, 0, 255 ),
				'data'       => $finding->data,
				'status'     => self::STATUS_OPEN,
				'run_id'     => $run_id,
				'first_seen' => $now,
				'last_seen'  => $now,
			]
		);
	}

	/**
	 * Refresh an existing unresolved finding seen again by a run.
	 *
	 * @param int     $id      Finding ID.
	 * @param Finding $finding Latest Finding.
	 * @param int     $run_id  Run ID.
	 */
	public function touch( int $id, Finding $finding, int $run_id ): void {
		global $wpdb;

		$wpdb->update(
			$this->table(),
			[
				'severity'  => $finding->severity->value,
				'title'     => mb_substr( $finding->title, 0, 255 ),
				'data'      => (string) wp_json_encode( $finding->data ),
				'run_id'    => $run_id,
				'last_seen' => self::now(),
			],
			[ 'id' => $id ]
		);
	}

	/**
	 * Resolve findings.
	 *
	 * @param list<int> $ids Finding IDs.
	 */
	public function resolve( array $ids ): void {
		global $wpdb;

		$now = self::now();
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$wpdb->update(
				$this->table(),
				[
					'status'      => self::STATUS_RESOLVED,
					'resolved_at' => $now,
				],
				[ 'id' => $id ]
			);
		}
	}

	/**
	 * Change status from the console (accept risk, mute, reopen, resolve).
	 *
	 * @param int         $id     Finding ID.
	 * @param string      $status One of STATUSES.
	 * @param string|null $note   Optional note (accepted risk reason).
	 */
	public function set_status( int $id, string $status, ?string $note = null ): bool {
		global $wpdb;

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}

		$fields = [
			'status'      => $status,
			'resolved_at' => self::STATUS_RESOLVED === $status ? self::now() : null,
		];
		if ( null !== $note ) {
			$fields['note'] = $note;
		}

		return false !== $wpdb->update( $this->table(), $fields, [ 'id' => $id ] );
	}

	/**
	 * Record that alerts went out (Notify lane, A2).
	 *
	 * @param list<int> $ids Finding IDs.
	 */
	public function mark_alerted( array $ids ): void {
		global $wpdb;

		$now = self::now();
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$wpdb->update( $this->table(), [ 'alerted_at' => $now ], [ 'id' => $id ] );
		}
	}

	/**
	 * Open findings per severity (status 'open' only).
	 *
	 * @param int|null $blog_id Site.
	 *
	 * @return array<string, int> severity => count, all five severities present.
	 */
	public function open_counts_by_severity( ?int $blog_id = null ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT severity, COUNT(*) AS n FROM %i WHERE blog_id = %d AND status = %s GROUP BY severity',
				$this->table(),
				$this->blog_id( $blog_id ),
				self::STATUS_OPEN
			),
			ARRAY_A
		);

		$counts = array_fill_keys( [ 'critical', 'high', 'medium', 'low', 'info' ], 0 );
		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['severity'] ] = (int) $row['n'];
		}

		return $counts;
	}

	/**
	 * Paginated, filtered list.
	 *
	 * @param array<string, mixed> $args Filters. `status` = one of STATUSES or 'unresolved'; `since`
	 *        Keys: blog_id?: int, check_id?: string, check_ids?: list<string>, severity?: string,
	 *        status?: string, alerted?: bool, since?: string, run_id?: int, page?: int,
	 *        per_page?: int.
	 *         filters first_seen (UTC); `alerted` false = alerted_at IS NULL.
	 *
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function query( array $args = [] ): array {
		global $wpdb;

		$where = $wpdb->prepare( ' WHERE blog_id = %d', $this->blog_id( $args['blog_id'] ?? null ) );

		if ( ! empty( $args['check_id'] ) ) {
			$where .= $wpdb->prepare( ' AND check_id = %s', $args['check_id'] );
		}
		if ( ! empty( $args['check_ids'] ) ) {
			$ids    = array_values( array_map( 'strval', $args['check_ids'] ) );
			$where .= $wpdb->prepare( ' AND check_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%s' ) ) . ')', ...$ids ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholder list generated to match $ids.
		}
		if ( ! empty( $args['severity'] ) ) {
			$where .= $wpdb->prepare( ' AND severity = %s', $args['severity'] );
		}
		if ( ! empty( $args['status'] ) ) {
			$where .= 'unresolved' === $args['status']
				? ' AND resolved_at IS NULL'
				: $wpdb->prepare( ' AND status = %s', $args['status'] );
		}
		if ( isset( $args['alerted'] ) ) {
			$where .= $args['alerted'] ? ' AND alerted_at IS NOT NULL' : ' AND alerted_at IS NULL';
		}
		if ( ! empty( $args['since'] ) ) {
			$where .= $wpdb->prepare( ' AND first_seen >= %s', $args['since'] );
		}
		if ( ! empty( $args['run_id'] ) ) {
			$where .= $wpdb->prepare( ' AND run_id = %d', $args['run_id'] );
		}

		$from = $wpdb->prepare( 'FROM %i', $this->table() );

		return [
			'items' => $this->select( "SELECT * {$from}{$where} ORDER BY last_seen DESC, id DESC" . $this->paginate( (int) ( $args['page'] ?? 1 ), (int) ( $args['per_page'] ?? 50 ) ) ),
			'total' => $this->count( "SELECT COUNT(*) {$from}{$where}" ),
		];
	}
}
