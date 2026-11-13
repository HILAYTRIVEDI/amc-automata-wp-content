<?php
/**
 * In-memory capture buffer, flushed once in `shutdown`.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Capture;

use AMC\Core\KillSwitch;
use AMC\Storage\EventsRepository;
use AMC\Storage\QueriesRepository;

/**
 * Class Buffer
 *
 * The only place the capture layer touches the database, and only in flush().
 *
 * Hot path (add_event / add_query): an array lookup and an increment. No
 * hashing, no regex, no options, no queries. Normalisation, signatures and
 * sources are computed in flush().
 *
 * Cap: at most MAX_ENTRIES distinct entries (events and slow queries together)
 * are held per request. An occurrence of an entry that is already held only
 * increments its counter, so a warning inside a hot loop stays cheap and
 * accurate. An occurrence that would need a new entry beyond the cap is
 * dropped and counted; flush() reports the number through the
 * `amc_capture_events_dropped` action.
 *
 * Kill switch: KillSwitch::is_active() is read once, at flush time, and only
 * when something was buffered. When it is active the buffer is discarded.
 *
 * @since 0.1.0
 */
final class Buffer {

	/**
	 * Maximum number of distinct buffered entries per request.
	 */
	public const MAX_ENTRIES = 200;

	/**
	 * Request-wide instance used by the capture classes.
	 *
	 * @var Buffer|null
	 */
	private static ?self $shared = null;

	/**
	 * Buffered events, keyed by "blog|type|file|line|message".
	 *
	 * @var array<string, array{type: string, message: string, file: string, line: int, blog_id: int, ts: int, count: int}>
	 */
	private array $events = [];

	/**
	 * Buffered slow queries, keyed by "blog|caller|sql".
	 *
	 * @var array<string, array{sql: string, caller: string, blog_id: int, ts: int, count: int, total_ms: float, max_ms: float}>
	 */
	private array $queries = [];

	/**
	 * Occurrences dropped because the cap was reached.
	 *
	 * @var int
	 */
	private int $dropped = 0;

	/**
	 * Constructor.
	 *
	 * @param EventsRepository|null  $events_repo  Events repository (test seam).
	 * @param QueriesRepository|null $queries_repo Queries repository (test seam).
	 */
	public function __construct(
		private ?EventsRepository $events_repo = null,
		private ?QueriesRepository $queries_repo = null
	) {
	}

	/**
	 * The request-wide buffer.
	 */
	public static function shared(): self {
		self::$shared ??= new self();

		return self::$shared;
	}

	/**
	 * Whether the `AMC_COPILOT_DISABLED` constant switches capture off.
	 *
	 * The constant is free to read; the `kill_switch` setting is not (the
	 * option is not autoloaded, so reading it costs a query) and is checked
	 * at flush time instead.
	 */
	public static function disabled_by_constant(): bool {
		return KillSwitch::is_locked_by_constant() && KillSwitch::is_active();
	}

	/**
	 * Flush in `shutdown`, last. Idempotent.
	 */
	public function register_flush(): void {
		if ( false === has_action( 'shutdown', [ $this, 'flush' ] ) ) {
			add_action( 'shutdown', [ $this, 'flush' ], 999 );
		}
	}

	/**
	 * Record one event occurrence.
	 *
	 * @param string $type    fatal | warning | notice | deprecation | doing_it_wrong.
	 * @param string $message Raw message.
	 * @param string $file    Absolute file.
	 * @param int    $line    Line.
	 */
	public function add_event( string $type, string $message, string $file, int $line ): void {
		$blog_id = get_current_blog_id();
		$key     = $blog_id . '|' . $type . '|' . $file . '|' . $line . '|' . $message;

		if ( isset( $this->events[ $key ] ) ) {
			++$this->events[ $key ]['count'];
			return;
		}

		if ( $this->is_full() ) {
			++$this->dropped;
			return;
		}

		$this->events[ $key ] = [
			'type'    => $type,
			'message' => $message,
			'file'    => $file,
			'line'    => $line,
			'blog_id' => $blog_id,
			'ts'      => time(),
			'count'   => 1,
		];
	}

	/**
	 * Record one slow query.
	 *
	 * @param string $sql    Raw SQL.
	 * @param string $caller Caller summary.
	 * @param float  $ms     Duration in milliseconds.
	 */
	public function add_query( string $sql, string $caller, float $ms ): void {
		$blog_id = get_current_blog_id();
		$key     = $blog_id . '|' . $caller . '|' . $sql;

		if ( isset( $this->queries[ $key ] ) ) {
			++$this->queries[ $key ]['count'];
			$this->queries[ $key ]['total_ms'] += $ms;
			$this->queries[ $key ]['max_ms']    = max( $this->queries[ $key ]['max_ms'], $ms );
			return;
		}

		if ( $this->is_full() ) {
			++$this->dropped;
			return;
		}

		$this->queries[ $key ] = [
			'sql'      => $sql,
			'caller'   => $caller,
			'blog_id'  => $blog_id,
			'ts'       => time(),
			'count'    => 1,
			'total_ms' => $ms,
			'max_ms'   => $ms,
		];
	}

	/**
	 * Count an occurrence that the caller chose not to record (buffer full).
	 */
	public function note_dropped(): void {
		++$this->dropped;
	}

	/**
	 * Whether no new distinct entry fits.
	 */
	public function is_full(): bool {
		return count( $this->events ) + count( $this->queries ) >= self::MAX_ENTRIES;
	}

	/**
	 * Distinct entries held.
	 */
	public function size(): int {
		return count( $this->events ) + count( $this->queries );
	}

	/**
	 * Occurrences dropped so far (since the last flush).
	 */
	public function dropped(): int {
		return $this->dropped;
	}

	/**
	 * `shutdown` callback: write the buffer.
	 */
	public function flush(): void {
		$this->drain();
	}

	/**
	 * Write the buffer with ONE upsert per table, then empty it.
	 *
	 * @return bool True when something was written.
	 */
	public function drain(): bool {
		if ( [] === $this->events && [] === $this->queries ) {
			$this->dropped = 0;
			return false;
		}

		$events        = $this->events;
		$queries       = $this->queries;
		$dropped       = $this->dropped;
		$this->events  = [];
		$this->queries = [];
		$this->dropped = 0;

		if ( KillSwitch::is_active() ) {
			return false;
		}

		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );

		try {
			$written = $this->write_events( $events ) | $this->write_queries( $queries );
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}

		if ( $dropped > 0 ) {
			/**
			 * Fires when a request produced more distinct events than the buffer holds.
			 *
			 * @param int $dropped Occurrences that were not recorded.
			 */
			do_action( 'amc_capture_events_dropped', $dropped );
		}

		return (bool) $written;
	}

	/**
	 * Aggregate and upsert events.
	 *
	 * @param array<string, array{type: string, message: string, file: string, line: int, blog_id: int, ts: int, count: int}> $events Buffered events.
	 */
	private function write_events( array $events ): int {
		$rows = [];

		foreach ( $events as $event ) {
			$file      = Signature::relative_file( $event['file'] );
			$signature = Signature::of( $event['type'], $file, $event['line'], $event['message'] );
			$hour      = EventsRepository::hour( $event['ts'] );
			$key       = $event['blog_id'] . '|' . $signature . '|' . $hour;

			if ( isset( $rows[ $key ] ) ) {
				$rows[ $key ]['count']    += $event['count'];
				$rows[ $key ]['last_seen'] = max( $rows[ $key ]['last_seen'], EventsRepository::now( $event['ts'] ) );
				continue;
			}

			$rows[ $key ] = [
				'blog_id'   => $event['blog_id'],
				'type'      => $event['type'],
				'signature' => $signature,
				'message'   => $event['message'],
				'source'    => Signature::source( $event['file'] ),
				'file'      => $file,
				'line'      => $event['line'],
				'hour'      => $hour,
				'count'     => $event['count'],
				'last_seen' => EventsRepository::now( $event['ts'] ),
			];
		}

		if ( [] === $rows ) {
			return 0;
		}

		$this->events_repo ??= new EventsRepository();

		return (int) $this->events_repo->upsert_many( array_values( $rows ) );
	}

	/**
	 * Aggregate and upsert slow queries.
	 *
	 * @param array<string, array{sql: string, caller: string, blog_id: int, ts: int, count: int, total_ms: float, max_ms: float}> $queries Buffered queries.
	 */
	private function write_queries( array $queries ): int {
		$rows = [];

		foreach ( $queries as $query ) {
			$sql       = Signature::sql( $query['sql'] );
			$signature = md5( $sql . '|' . $query['caller'] );
			$hour      = EventsRepository::hour( $query['ts'] );
			$key       = $query['blog_id'] . '|' . $signature . '|' . $hour;

			if ( isset( $rows[ $key ] ) ) {
				$rows[ $key ]['total_ms'] += $query['total_ms'];
				$rows[ $key ]['count']    += $query['count'];
				$rows[ $key ]['max_ms']    = max( $rows[ $key ]['max_ms'], $query['max_ms'] );
				continue;
			}

			$rows[ $key ] = [
				'blog_id'    => $query['blog_id'],
				'signature'  => $signature,
				'sql_sample' => $sql,
				'caller'     => $query['caller'],
				'hour'       => $hour,
				'count'      => $query['count'],
				'total_ms'   => $query['total_ms'],
				'max_ms'     => $query['max_ms'],
			];
		}

		if ( [] === $rows ) {
			return 0;
		}

		foreach ( $rows as &$row ) {
			$row['avg_ms'] = $row['total_ms'] / max( 1, $row['count'] );
		}
		unset( $row );

		$this->queries_repo ??= new QueriesRepository();

		return (int) $this->queries_repo->upsert_many( array_values( $rows ) );
	}
}
