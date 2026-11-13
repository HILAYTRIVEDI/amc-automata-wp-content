<?php
/**
 * Repository for `amc_runs`.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Storage;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom table repository.

/**
 * Class RunsRepository
 *
 * Row shape returned by every read:
 *   id int, blog_id int, check_id string, trigger_type 'cron'|'manual'|'cli',
 *   status string (see STATUSES), summary string, data array, user_id int,
 *   created_at string, started_at ?string, finished_at ?string, duration_ms int.
 *
 * Lifecycle: queued -> running -> pass|warn|fail|info|error.
 * A cron run is created directly as 'running'. "Run now" creates a 'queued'
 * row that the scheduler claims; the client polls GET /amc/v1/runs/{id}.
 *
 * Owner: Wave 0. Other lanes call it, they do not edit it.
 *
 * @since 0.1.0
 */
final class RunsRepository extends AbstractRepository {

	protected const TABLE       = Tables::RUNS;
	protected const INT_COLUMNS = [ 'id', 'blog_id', 'user_id', 'duration_ms' ];

	public const STATUS_QUEUED  = 'queued';
	public const STATUS_RUNNING = 'running';
	public const STATUS_ERROR   = 'error';

	/**
	 * Every run status. pass/warn/fail/info come from Checks\Status.
	 */
	public const STATUSES = [ 'queued', 'running', 'pass', 'warn', 'fail', 'info', 'error' ];

	/**
	 * Statuses of a finished run that produced a Result.
	 */
	public const RESULT_STATUSES = [ 'pass', 'warn', 'fail', 'info' ];

	/**
	 * Triggers.
	 */
	public const TRIGGERS = [ 'cron', 'manual', 'cli' ];

	/**
	 * Create a queued run ("Run now"). The scheduler picks it up.
	 *
	 * @param string   $check_id Check id.
	 * @param int      $user_id  User who asked.
	 * @param int|null $blog_id  Site (null = current).
	 *
	 * @return int Run ID.
	 */
	public function create_queued( string $check_id, int $user_id = 0, ?int $blog_id = null ): int {
		return $this->insert_row(
			[
				'blog_id'      => $this->blog_id( $blog_id ),
				'check_id'     => $check_id,
				'trigger_type' => 'manual',
				'status'       => self::STATUS_QUEUED,
				'user_id'      => $user_id,
				'created_at'   => self::now(),
			]
		);
	}

	/**
	 * Create a run that starts now (cron or CLI).
	 *
	 * @param string   $check_id Check id.
	 * @param string   $trigger  'cron' | 'cli' | 'manual'.
	 * @param int      $user_id  User (0 for cron).
	 * @param int|null $blog_id  Site (null = current).
	 *
	 * @return int Run ID.
	 */
	public function create_running( string $check_id, string $trigger, int $user_id = 0, ?int $blog_id = null ): int {
		$now = self::now();

		return $this->insert_row(
			[
				'blog_id'      => $this->blog_id( $blog_id ),
				'check_id'     => $check_id,
				'trigger_type' => $trigger,
				'status'       => self::STATUS_RUNNING,
				'user_id'      => $user_id,
				'created_at'   => $now,
				'started_at'   => $now,
			]
		);
	}

	/**
	 * Atomically claim a queued run (queued -> running).
	 *
	 * @param int $id Run ID.
	 *
	 * @return bool True if THIS caller claimed it.
	 */
	public function claim( int $id ): bool {
		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, started_at = %s WHERE id = %d AND status = %s',
				$this->table(),
				self::STATUS_RUNNING,
				self::now(),
				$id,
				self::STATUS_QUEUED
			)
		);

		return 1 === (int) $updated;
	}

	/**
	 * Store the outcome of a run.
	 *
	 * @param int                  $id          Run ID.
	 * @param string               $status      pass|warn|fail|info|error.
	 * @param string               $summary     One line.
	 * @param array<string, mixed> $data        Result data (JSON).
	 * @param int                  $duration_ms Duration.
	 */
	public function finish( int $id, string $status, string $summary, array $data, int $duration_ms ): void {
		global $wpdb;

		$wpdb->update(
			$this->table(),
			[
				'status'      => $status,
				'summary'     => mb_substr( $summary, 0, 255 ),
				'data'        => (string) wp_json_encode( $data ),
				'finished_at' => self::now(),
				'duration_ms' => max( 0, $duration_ms ),
			],
			[ 'id' => $id ]
		);
	}

	/**
	 * Queued runs for a site, oldest first.
	 *
	 * @param int|null $blog_id Site.
	 * @param int      $limit   Max rows.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function queued( ?int $blog_id = null, int $limit = 20 ): array {
		global $wpdb;

		return $this->select(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE blog_id = %d AND status = %s ORDER BY created_at ASC, id ASC LIMIT %d',
				$this->table(),
				$this->blog_id( $blog_id ),
				self::STATUS_QUEUED,
				$limit
			)
		);
	}

	/**
	 * Whether a check already has a queued or running run (avoid double-queueing).
	 *
	 * @param string   $check_id Check id.
	 * @param int|null $blog_id  Site.
	 *
	 * @return int The pending run ID, or 0.
	 */
	public function pending_id( string $check_id, ?int $blog_id = null ): int {
		global $wpdb;

		return $this->count(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE blog_id = %d AND check_id = %s AND status IN (%s, %s) ORDER BY id DESC LIMIT 1',
				$this->table(),
				$this->blog_id( $blog_id ),
				$check_id,
				self::STATUS_QUEUED,
				self::STATUS_RUNNING
			)
		);
	}

	/**
	 * Mark runs stuck in 'running' longer than $seconds as 'error' (crashed PHP).
	 *
	 * @param int $seconds Age threshold (use the lock TTL).
	 *
	 * @return int Rows changed.
	 */
	public function fail_stale( int $seconds ): int {
		global $wpdb;

		return (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, summary = %s, finished_at = %s WHERE status = %s AND started_at < %s',
				$this->table(),
				self::STATUS_ERROR,
				'Run did not finish (timed out or PHP stopped).',
				self::now(),
				self::STATUS_RUNNING,
				self::now( time() - $seconds )
			)
		);
	}

	/**
	 * Latest FINISHED run (with a Result) of one check.
	 *
	 * @param string   $check_id Check id.
	 * @param int|null $blog_id  Site.
	 * @param int      $before_id Only runs with id < this (0 = no limit). Lets
	 *                            the runner fetch the previous run's data.
	 *
	 * @return array<string, mixed>|null
	 */
	public function latest( string $check_id, ?int $blog_id = null, int $before_id = 0 ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Four statuses spread from RESULT_STATUSES.
		$sql = $wpdb->prepare(
			'SELECT * FROM %i WHERE blog_id = %d AND check_id = %s AND status IN (%s, %s, %s, %s)',
			$this->table(),
			$this->blog_id( $blog_id ),
			$check_id,
			...self::RESULT_STATUSES
		);

		if ( $before_id > 0 ) {
			$sql .= $wpdb->prepare( ' AND id < %d', $before_id );
		}

		$rows = $this->select( $sql . ' ORDER BY id DESC LIMIT 1' );

		return $rows[0] ?? null;
	}

	/**
	 * Latest finished run of every check, keyed by check id.
	 *
	 * @param int|null $blog_id Site.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function latest_per_check( ?int $blog_id = null ): array {
		global $wpdb;

		$rows = $this->select(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Four statuses spread from RESULT_STATUSES.
			$wpdb->prepare(
				'SELECT r.* FROM %i r INNER JOIN (
					SELECT MAX(id) AS id FROM %i WHERE blog_id = %d AND status IN (%s, %s, %s, %s) GROUP BY check_id
				) latest ON latest.id = r.id',
				$this->table(),
				$this->table(),
				$this->blog_id( $blog_id ),
				...self::RESULT_STATUSES
			)
		);

		$by_check = [];
		foreach ( $rows as $row ) {
			$by_check[ $row['check_id'] ] = $row;
		}

		return $by_check;
	}

	/**
	 * Time of the most recent run (any status) per check, for the scheduler.
	 *
	 * @param int|null $blog_id Site.
	 *
	 * @return array<string, int> check_id => unix timestamp.
	 */
	public function last_run_times( ?int $blog_id = null ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT check_id, MAX(created_at) AS last_at FROM %i WHERE blog_id = %d GROUP BY check_id',
				$this->table(),
				$this->blog_id( $blog_id )
			),
			ARRAY_A
		);

		$times = [];
		foreach ( (array) $rows as $row ) {
			$times[ (string) $row['check_id'] ] = (int) strtotime( $row['last_at'] . ' UTC' );
		}

		return $times;
	}

	/**
	 * Paginated list for the Runs screen.
	 *
	 * @param array<string, mixed> $args Filters (all optional). since/until are UTC 'Y-m-d H:i:s' on created_at.
	 *        Keys: blog_id?: int, check_id?: string, status?: string, trigger?: string,
	 *        since?: string, until?: string, page?: int, per_page?: int.
	 *
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function query( array $args = [] ): array {
		global $wpdb;

		$where = $wpdb->prepare( ' WHERE blog_id = %d', $this->blog_id( $args['blog_id'] ?? null ) );

		if ( ! empty( $args['check_id'] ) ) {
			$where .= $wpdb->prepare( ' AND check_id = %s', $args['check_id'] );
		}
		if ( ! empty( $args['status'] ) ) {
			$where .= $wpdb->prepare( ' AND status = %s', $args['status'] );
		}
		if ( ! empty( $args['trigger'] ) ) {
			$where .= $wpdb->prepare( ' AND trigger_type = %s', $args['trigger'] );
		}
		if ( ! empty( $args['since'] ) ) {
			$where .= $wpdb->prepare( ' AND created_at >= %s', $args['since'] );
		}
		if ( ! empty( $args['until'] ) ) {
			$where .= $wpdb->prepare( ' AND created_at < %s', $args['until'] );
		}

		$from = $wpdb->prepare( 'FROM %i', $this->table() );

		return [
			'items' => $this->select( "SELECT * {$from}{$where} ORDER BY id DESC" . $this->paginate( (int) ( $args['page'] ?? 1 ), (int) ( $args['per_page'] ?? 50 ) ) ),
			'total' => $this->count( "SELECT COUNT(*) {$from}{$where}" ),
		];
	}
}
