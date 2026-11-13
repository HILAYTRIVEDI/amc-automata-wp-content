<?php
/**
 * Tests for the retention job.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Storage;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Tests back-date and count rows directly.

use AMC\Checks\Finding;
use AMC\Checks\Severity;
use AMC\Core\Settings;
use AMC\Runner\Lock;
use AMC\Storage\AbstractRepository;
use AMC\Storage\ChangesRepository;
use AMC\Storage\EventsRepository;
use AMC\Storage\FindingsRepository;
use AMC\Storage\QueriesRepository;
use AMC\Storage\Retention;
use AMC\Storage\RunsRepository;
use AMC\Storage\SnapshotsRepository;
use AMC\Storage\Tables;
use AMC\Tests\TestCase;

/**
 * Class RetentionTest
 */
final class RetentionTest extends TestCase {

	/**
	 * A time that is `$days` days in the past, as a DB datetime.
	 *
	 * @param int $days Days ago.
	 */
	private function ago( int $days ): string {
		return gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
	}

	/**
	 * Set one column of one row.
	 *
	 * @param string $table  Table constant.
	 * @param int    $id     Row ID.
	 * @param string $column Column.
	 * @param string $value  Value.
	 */
	private function backdate( string $table, int $id, string $column, string $value ): void {
		global $wpdb;
		$wpdb->update( Tables::name( $table ), [ $column => $value ], [ 'id' => $id ] );
	}

	/**
	 * Seed one old and one fresh row in every table. Old = 400 days (> 13 months), fresh = 10 days.
	 *
	 * @return array<string, array{old: int, fresh: int}>
	 */
	private function seed(): array {
		$ids  = [];
		$runs = new RunsRepository();
		foreach ( [
			'old'   => 400,
			'fresh' => 10,
		] as $label => $days ) {
			$run = $runs->create_running( 'ret.check', 'cron' );
			$this->backdate( Tables::RUNS, $run, 'created_at', $this->ago( $days ) );
			$ids['runs'][ $label ] = $run;

			$findings = new FindingsRepository();
			$finding  = $findings->insert_finding( new Finding( "k-{$label}", Severity::LOW, 'T' ), 'ret.check', $run );
			$findings->resolve( [ $finding ] );
			$this->backdate( Tables::FINDINGS, $finding, 'resolved_at', $this->ago( $days ) );
			$ids['findings'][ $label ] = $finding;

			$changes                  = new ChangesRepository();
			$ids['changes'][ $label ] = $changes->insert(
				[
					'type'       => 'plugin',
					'action'     => 'update',
					'item'       => "ret-{$label}",
					'created_at' => $this->ago( $days ),
				]
			);

			$snapshots                  = new SnapshotsRepository();
			$snapshot                   = $snapshots->insert( 'plugins', [ $label => 1 ] );
			$ids['snapshots'][ $label ] = $snapshot;
			$this->backdate( Tables::SNAPSHOTS, $snapshot, 'taken_at', $this->ago( $days ) );
		}

		$unresolved = ( new FindingsRepository() )->insert_finding( new Finding( 'still-open', Severity::HIGH, 'Open' ), 'ret.check', 1 );
		$this->backdate( Tables::FINDINGS, $unresolved, 'first_seen', $this->ago( 500 ) );
		$ids['findings']['open'] = $unresolved;

		$events = new EventsRepository();
		$events->upsert_many(
			[
				[
					'type'      => 'warning',
					'signature' => md5( 'ret-old' ),
					'hour'      => gmdate( 'Y-m-d H:00:00', time() - 400 * DAY_IN_SECONDS ),
				],
				[
					'type'      => 'warning',
					'signature' => md5( 'ret-fresh' ),
					'hour'      => gmdate( 'Y-m-d H:00:00', time() - 10 * DAY_IN_SECONDS ),
				],
			]
		);
		$ids['events'] = [
			'old'   => md5( 'ret-old' ),
			'fresh' => md5( 'ret-fresh' ),
		];

		( new QueriesRepository() )->upsert_many(
			[
				[
					'signature' => md5( 'q-old' ),
					'hour'      => gmdate( 'Y-m-d H:00:00', time() - 100 * DAY_IN_SECONDS ),
					'max_ms'    => 1,
					'avg_ms'    => 1,
				],
				[
					'signature' => md5( 'q-fresh' ),
					'hour'      => gmdate( 'Y-m-d H:00:00', time() - 80 * DAY_IN_SECONDS ),
					'max_ms'    => 1,
					'avg_ms'    => 1,
				],
			]
		);

		return $ids;
	}

	/**
	 * Number of events/queries rows with a signature.
	 *
	 * @param string $table     Table constant.
	 * @param string $signature Signature.
	 */
	private function count_signature( string $table, string $signature ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE signature = %s', Tables::name( $table ), $signature ) );
	}

	public function test_every_table_loses_its_expired_rows_and_keeps_the_rest(): void {
		$ids = $this->seed();

		$deleted = ( new Retention() )->run();

		$this->assertNull( ( new RunsRepository() )->find( $ids['runs']['old'] ) );
		$this->assertNotNull( ( new RunsRepository() )->find( $ids['runs']['fresh'] ) );

		$findings = new FindingsRepository();
		$this->assertNull( $findings->find( $ids['findings']['old'] ), 'Resolved 13 months ago' );
		$this->assertNotNull( $findings->find( $ids['findings']['fresh'] ) );
		$this->assertNotNull( $findings->find( $ids['findings']['open'] ), 'Unresolved findings are never deleted, however old' );

		$this->assertNull( ( new ChangesRepository() )->find( $ids['changes']['old'] ) );
		$this->assertNotNull( ( new ChangesRepository() )->find( $ids['changes']['fresh'] ) );
		$this->assertNull( ( new SnapshotsRepository() )->find( $ids['snapshots']['old'] ) );
		$this->assertNotNull( ( new SnapshotsRepository() )->find( $ids['snapshots']['fresh'] ) );

		$this->assertSame( 0, $this->count_signature( Tables::EVENTS, $ids['events']['old'] ) );
		$this->assertSame( 1, $this->count_signature( Tables::EVENTS, $ids['events']['fresh'] ) );

		$this->assertSame( 0, $this->count_signature( Tables::QUERIES, md5( 'q-old' ) ), 'Queries expire after 90 days' );
		$this->assertSame( 1, $this->count_signature( Tables::QUERIES, md5( 'q-fresh' ) ), '80 days is inside the 90-day window although it is far inside 13 months' );

		$this->assertGreaterThanOrEqual( 1, $deleted['runs'] );
		$this->assertSame( [ 'runs', 'findings', 'events', 'queries', 'changes', 'snapshots' ], array_keys( $deleted ) );
	}

	public function test_retention_months_setting_moves_the_cutoff(): void {
		$ids = $this->seed();
		Settings::update( [ 'retention_months' => 1 ] );

		( new Retention() )->run();

		$this->assertNotNull( ( new RunsRepository() )->find( $ids['runs']['fresh'] ), '10 days old is inside one month' );

		$this->assertSame( 1, $this->count_signature( Tables::QUERIES, md5( 'q-fresh' ) ), 'Queries ignore the setting' );

		$run = ( new RunsRepository() )->create_running( 'ret.check', 'cron' );
		$this->backdate( Tables::RUNS, $run, 'created_at', $this->ago( 45 ) );
		( new Retention() )->run();
		$this->assertNull( ( new RunsRepository() )->find( $run ), '45 days is outside one month' );
	}

	public function test_rows_are_deleted_in_batches_until_a_short_batch(): void {
		$changes = new ChangesRepository();
		for ( $i = 0; $i < 5; $i++ ) {
			$changes->insert(
				[
					'type'       => 'plugin',
					'action'     => 'update',
					'item'       => "batch-{$i}",
					'created_at' => $this->ago( 500 ),
				]
			);
		}

		$calls     = [];
		$retention = new Retention(
			Retention::TIME_LIMIT,
			2,
			null,
			static function ( AbstractRepository $repo, string $column, string $cutoff, int $limit ) use ( &$calls ): int {
				$deleted = $repo->delete_older_than( $column, $cutoff, $limit );
				if ( $repo instanceof ChangesRepository ) {
					$calls[] = [ $column, $limit, $deleted ];
				}
				return $deleted;
			}
		);

		$result = $retention->run();

		$this->assertSame( [ [ 'created_at', 2, 2 ], [ 'created_at', 2, 2 ], [ 'created_at', 2, 1 ] ], $calls, 'Two full batches, then a short one stops the loop' );
		$this->assertSame( 5, $result['changes'] );
		$this->assertSame( 0, $changes->query( [ 'item' => 'batch-0' ] )['total'] );
	}

	public function test_default_batch_size_is_1000_and_budget_is_20_seconds(): void {
		$this->assertSame( 1000, Retention::BATCH_SIZE );
		$this->assertSame( 20.0, Retention::TIME_LIMIT );

		$limits = [];
		( new Retention(
			Retention::TIME_LIMIT,
			Retention::BATCH_SIZE,
			null,
			static function ( AbstractRepository $repo, string $column, string $cutoff, int $limit ) use ( &$limits ): int {
				unset( $repo, $column, $cutoff );
				$limits[] = $limit;
				return 0;
			}
		) )->run();

		$this->assertSame( array_fill( 0, 6, 1000 ), $limits );
	}

	public function test_time_budget_stops_the_run_and_schedules_a_follow_up(): void {
		$ticks     = 0;
		$calls     = 0;
		$retention = new Retention(
			20.0,
			1000,
			static function () use ( &$ticks ): float {
				++$ticks;
				return $ticks * 10.0;
			},
			static function () use ( &$calls ): int {
				++$calls;
				return 1000; // Always a full batch: there is always more to delete.
			}
		);
		$this->assertFalse( wp_next_scheduled( Retention::HOOK ) );

		$result = $retention->run();

		// Clock: start = 10; check 1 = 20 (10 s used) runs a batch; check 2 = 30 (20 s used) stops.
		$this->assertSame( 1, $calls );
		$this->assertSame( [ 'runs' => 1000 ], $result );
		$this->assertNotFalse( wp_next_scheduled( Retention::HOOK ), 'A follow-up run is scheduled' );
	}

	public function test_a_run_that_finishes_in_time_schedules_nothing(): void {
		( new Retention() )->run();

		$this->assertFalse( wp_next_scheduled( Retention::HOOK ) );
	}

	public function test_it_takes_the_retention_lock_and_releases_it(): void {
		$lock = Lock::named( 'retention' );
		$this->assertTrue( $lock->acquire() );

		$this->assertSame( [], ( new Retention() )->run(), 'Another run holds the lock' );

		$lock->release();
		$this->assertNotSame( [], ( new Retention() )->run() );

		$after = Lock::named( 'retention' );
		$this->assertTrue( $after->acquire(), 'The lock is released after a run' );
		$after->release();
	}

	public function test_lock_is_released_when_a_delete_throws(): void {
		$retention = new Retention(
			20.0,
			1000,
			null,
			static function (): int {
				throw new \RuntimeException( 'db gone' );
			}
		);

		try {
			$retention->run();
			$this->fail( 'Expected the exception' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'db gone', $e->getMessage() );
		}

		$lock = Lock::named( 'retention' );
		$this->assertTrue( $lock->acquire() );
		$lock->release();
	}

	public function test_kill_switch_stops_the_job(): void {
		$ids = $this->seed();
		Settings::update( [ 'kill_switch' => true ] );

		$this->assertSame( [], ( new Retention() )->run() );
		$this->assertNotNull( ( new RunsRepository() )->find( $ids['runs']['old'] ) );
	}

	public function test_cron_event_is_scheduled_on_activation_and_cleared_on_deactivation(): void {
		$this->assertNotFalse( has_action( Retention::HOOK ), 'The job is wired to its cron hook' );

		do_action( 'amc_activate' );
		$this->assertNotFalse( wp_next_scheduled( Retention::HOOK ) );
		$this->assertSame( 'daily', wp_get_schedule( Retention::HOOK ) );

		do_action( 'amc_deactivate' );
		$this->assertFalse( wp_next_scheduled( Retention::HOOK ) );
	}

	public function test_the_cron_callback_runs_the_job(): void {
		$ids = $this->seed();

		do_action( Retention::HOOK );

		$this->assertNull( ( new RunsRepository() )->find( $ids['runs']['old'] ) );
	}
}
