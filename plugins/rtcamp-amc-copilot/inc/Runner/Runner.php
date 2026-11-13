<?php
/**
 * Runs one check: lock, execute, store run, process findings.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Runner;

use AMC\Checks\Check;
use AMC\Checks\Context;
use AMC\Checks\Registry;
use AMC\Core\KillSwitch;
use AMC\Storage\FindingsRepository;
use AMC\Storage\RunsRepository;

/**
 * Class Runner
 *
 * The only code that executes checks. Three entry points:
 *
 *   run( $check_id, 'cron'|'cli' )  Run now in THIS process (cron, CLI).
 *   enqueue( $check_id, $user_id )  "Run now" from REST: creates a queued
 *                                    run row and returns its id. Never runs
 *                                    the check in the REST request.
 *   run_queued( $run_id )           Execute a queued row (Scheduler, cron).
 *
 * Steps of an execution (execute()):
 *   1. Refuse when the kill switch is on.
 *   2. switch_to_blog() when the run is for another site (multisite).
 *   3. Take the per-check lock (amc_locks, 10 min). Busy -> WP_Error 'amc_locked'.
 *   4. Build Context (blog, trigger, run id, clock, previous run data).
 *   5. Call Check::run(). A throw stores the run as 'error' and stops here.
 *   6. Store status/summary/data/duration on the run row.
 *   7. Dedupe findings against unresolved rows: new -> insert, seen again
 *      -> touch, missing -> resolve.
 *   8. do_action( 'amc_findings_processed', ProcessedFindings ).
 *   9. do_action( 'amc_run_completed', array $run_row ).
 *  10. Release the lock, restore_current_blog().
 *
 * @since 0.1.0
 */
final class Runner {

	/**
	 * Action fired with a ProcessedFindings payload.
	 */
	public const ACTION_FINDINGS = 'amc_findings_processed';

	/**
	 * Action fired with the finished run row (any final status, including error).
	 */
	public const ACTION_COMPLETED = 'amc_run_completed';

	/**
	 * Registry.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Runs repository.
	 *
	 * @var RunsRepository
	 */
	private RunsRepository $runs;

	/**
	 * Findings repository.
	 *
	 * @var FindingsRepository
	 */
	private FindingsRepository $findings;

	/**
	 * Constructor. All arguments are test seams.
	 *
	 * @param Registry|null           $registry Registry.
	 * @param RunsRepository|null     $runs     Runs repository.
	 * @param FindingsRepository|null $findings Findings repository.
	 */
	public function __construct( ?Registry $registry = null, ?RunsRepository $runs = null, ?FindingsRepository $findings = null ) {
		$this->registry = $registry ?? new Registry();
		$this->runs     = $runs ?? new RunsRepository();
		$this->findings = $findings ?? new FindingsRepository();
	}

	/**
	 * Run a check now, in this process.
	 *
	 * @param string   $check_id Check id.
	 * @param string   $trigger  'cron' | 'cli' | 'manual'.
	 * @param int      $user_id  Acting user (0 for cron).
	 * @param int|null $blog_id  Site (null = current).
	 *
	 * @return array<string, mixed>|\WP_Error The finished run row.
	 */
	public function run( string $check_id, string $trigger = 'cron', int $user_id = 0, ?int $blog_id = null ): array|\WP_Error {
		$check = $this->registry->get( $check_id );
		if ( null === $check ) {
			return self::unknown_check( $check_id );
		}

		return $this->with_blog(
			$blog_id ?? get_current_blog_id(),
			fn ( int $blog ): array|\WP_Error => $this->execute(
				$check,
				$blog,
				$trigger,
				fn (): int => $this->runs->create_running( $check_id, $trigger, $user_id, $blog )
			)
		);
	}

	/**
	 * Queue a "Run now". Returns the existing pending run if one is queued or running.
	 *
	 * @param string   $check_id Check id.
	 * @param int      $user_id  User who asked.
	 * @param int|null $blog_id  Site (null = current).
	 *
	 * @return int|\WP_Error Run ID to poll.
	 */
	public function enqueue( string $check_id, int $user_id = 0, ?int $blog_id = null ): int|\WP_Error {
		if ( ! $this->registry->has( $check_id ) ) {
			return self::unknown_check( $check_id );
		}

		if ( KillSwitch::is_active() ) {
			return self::disabled();
		}

		$blog_id = $blog_id ?? get_current_blog_id();
		$pending = $this->runs->pending_id( $check_id, $blog_id );

		if ( $pending > 0 ) {
			return $pending;
		}

		$run_id = $this->runs->create_queued( $check_id, $user_id, $blog_id );

		Scheduler::kick();

		return $run_id;
	}

	/**
	 * Execute a queued run row.
	 *
	 * @param int $run_id Run ID (status 'queued').
	 *
	 * @return array<string, mixed>|\WP_Error The finished run row.
	 */
	public function run_queued( int $run_id ): array|\WP_Error {
		$row = $this->runs->find( $run_id );

		if ( null === $row || RunsRepository::STATUS_QUEUED !== $row['status'] ) {
			return new \WP_Error( 'amc_not_queued', __( 'That run is not queued.', 'rtcamp-amc-copilot' ), [ 'status' => 409 ] );
		}

		$check = $this->registry->get( (string) $row['check_id'] );
		if ( null === $check ) {
			$this->runs->finish( $run_id, RunsRepository::STATUS_ERROR, __( 'Check is no longer registered.', 'rtcamp-amc-copilot' ), [], 0 );
			return self::unknown_check( (string) $row['check_id'] );
		}

		return $this->with_blog(
			(int) $row['blog_id'],
			fn ( int $blog ): array|\WP_Error => $this->execute(
				$check,
				$blog,
				(string) $row['trigger_type'],
				fn (): int => $this->runs->claim( $run_id ) ? $run_id : 0
			)
		);
	}

	/**
	 * Core of every run. See the class docblock for the steps.
	 *
	 * @param Check    $check      Check.
	 * @param int      $blog_id    Site (already switched to).
	 * @param string   $trigger    Trigger.
	 * @param callable $create_run Returns the run ID to write to (0 = could not claim).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function execute( Check $check, int $blog_id, string $trigger, callable $create_run ): array|\WP_Error {
		if ( KillSwitch::is_active() ) {
			return self::disabled();
		}

		$lock = Lock::for_check( $check->id(), $blog_id );
		if ( ! $lock->acquire() ) {
			return new \WP_Error( 'amc_locked', __( 'This check is already running.', 'rtcamp-amc-copilot' ), [ 'status' => 409 ] );
		}

		try {
			$run_id = (int) $create_run();
			if ( $run_id <= 0 ) {
				return new \WP_Error( 'amc_not_claimed', __( 'The run could not be started.', 'rtcamp-amc-copilot' ), [ 'status' => 409 ] );
			}

			$previous = $this->runs->latest( $check->id(), $blog_id, $run_id );
			$context  = new Context(
				$blog_id,
				$trigger,
				$run_id,
				new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ),
				null !== $previous ? (array) $previous['data'] : null
			);

			$started = microtime( true );

			try {
				$result = $check->run( $context );
			} catch ( \Throwable $e ) {
				$this->runs->finish(
					$run_id,
					RunsRepository::STATUS_ERROR,
					/* translators: %s: Exception message. */
					sprintf( __( 'Check failed: %s', 'rtcamp-amc-copilot' ), $e->getMessage() ),
					[
						'exception' => get_class( $e ),
						'message'   => $e->getMessage(),
						'file'      => $e->getFile(),
						'line'      => $e->getLine(),
					],
					self::elapsed_ms( $started )
				);

				return $this->completed( $run_id );
			}

			$this->runs->finish( $run_id, $result->status->value, $result->summary, $result->data, self::elapsed_ms( $started ) );

			$processed = $this->process_findings( $check->id(), $run_id, $blog_id, $trigger, $result->findings );

			/**
			 * Fires after a run's findings are stored, deduplicated and resolved.
			 *
			 * @param ProcessedFindings $processed New, ongoing and resolved findings.
			 */
			do_action( 'amc_findings_processed', $processed );

			return $this->completed( $run_id );
		} finally {
			$lock->release();
		}
	}

	/**
	 * Dedupe findings against unresolved rows and resolve the missing ones.
	 *
	 * @param string                    $check_id Check id.
	 * @param int                       $run_id   Run ID.
	 * @param int                       $blog_id  Site.
	 * @param string                    $trigger  Trigger.
	 * @param list<\AMC\Checks\Finding> $findings Findings from the Result.
	 */
	private function process_findings( string $check_id, int $run_id, int $blog_id, string $trigger, array $findings ): ProcessedFindings {
		$unresolved = $this->findings->unresolved_for_check( $check_id, $blog_id );
		$new        = [];
		$ongoing    = [];
		$seen       = [];

		foreach ( $findings as $finding ) {
			if ( isset( $seen[ $finding->dedupe_key ] ) ) {
				continue; // Same key twice in one result: keep the first.
			}
			$seen[ $finding->dedupe_key ] = true;

			if ( isset( $unresolved[ $finding->dedupe_key ] ) ) {
				$id = (int) $unresolved[ $finding->dedupe_key ]['id'];
				$this->findings->touch( $id, $finding, $run_id );
				$ongoing[] = $this->findings->find( $id );
				continue;
			}

			$id = $this->findings->insert_finding( $finding, $check_id, $run_id, $blog_id );
			if ( $id > 0 ) {
				$new[] = $this->findings->find( $id );
			}
		}

		$to_resolve = array_diff_key( $unresolved, $seen );
		$this->findings->resolve( array_map( static fn ( array $row ): int => (int) $row['id'], array_values( $to_resolve ) ) );

		$resolved = [];
		foreach ( $to_resolve as $row ) {
			$resolved[] = $this->findings->find( (int) $row['id'] );
		}

		return new ProcessedFindings(
			$run_id,
			$check_id,
			$blog_id,
			$trigger,
			array_values( array_filter( $new ) ),
			array_values( array_filter( $ongoing ) ),
			array_values( array_filter( $resolved ) )
		);
	}

	/**
	 * Load the finished run row and fire amc_run_completed.
	 *
	 * @param int $run_id Run ID.
	 *
	 * @return array<string, mixed>
	 */
	private function completed( int $run_id ): array {
		$row = (array) $this->runs->find( $run_id );

		/**
		 * Fires after any run finishes (including status 'error').
		 *
		 * @param array<string, mixed> $row Run row (RunsRepository shape).
		 */
		do_action( 'amc_run_completed', $row );

		return $row;
	}

	/**
	 * Run a callback on another site of a multisite network.
	 *
	 * @param int      $blog_id  Target site.
	 * @param callable $callback Receives the blog ID.
	 */
	private function with_blog( int $blog_id, callable $callback ): mixed {
		$switched = is_multisite() && get_current_blog_id() !== $blog_id;

		if ( $switched ) {
			switch_to_blog( $blog_id );
		}

		try {
			return $callback( $blog_id );
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Milliseconds since $started.
	 *
	 * @param float $started microtime(true).
	 */
	private static function elapsed_ms( float $started ): int {
		return (int) round( ( microtime( true ) - $started ) * 1000 );
	}

	/**
	 * Error for an unknown check id.
	 *
	 * @param string $check_id Check id.
	 */
	private static function unknown_check( string $check_id ): \WP_Error {
		/* translators: %s: Check id. */
		return new \WP_Error( 'amc_unknown_check', sprintf( __( 'Unknown check "%s".', 'rtcamp-amc-copilot' ), $check_id ), [ 'status' => 404 ] );
	}

	/**
	 * Error when the kill switch is on.
	 */
	private static function disabled(): \WP_Error {
		return new \WP_Error( 'amc_disabled', __( 'AMC Copilot is switched off (kill switch).', 'rtcamp-amc-copilot' ), [ 'status' => 503 ] );
	}
}
