<?php
/**
 * Tests for the custom-table repositories.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Storage;

use AMC\Checks\Finding;
use AMC\Checks\Severity;
use AMC\Storage\ChangesRepository;
use AMC\Storage\EventsRepository;
use AMC\Storage\FindingsRepository;
use AMC\Storage\QueriesRepository;
use AMC\Storage\RunsRepository;
use AMC\Storage\SnapshotsRepository;
use AMC\Tests\TestCase;

/**
 * Class RepositoriesTest
 */
final class RepositoriesTest extends TestCase {

	public function test_runs_queue_claim_finish_lifecycle(): void {
		$runs = new RunsRepository();
		$id   = $runs->create_queued( 'updates.core', 7 );

		$this->assertSame( 'queued', $runs->find( $id )['status'] );
		$this->assertSame( $id, $runs->pending_id( 'updates.core' ) );
		$this->assertCount( 1, $runs->queued() );

		$this->assertTrue( $runs->claim( $id ) );
		$this->assertFalse( $runs->claim( $id ), 'A run can be claimed only once' );

		$runs->finish( $id, 'pass', 'OK', [ 'a' => 1 ], 12 );

		$row = $runs->find( $id );
		$this->assertSame( 'pass', $row['status'] );
		$this->assertSame( [ 'a' => 1 ], $row['data'] );
		$this->assertSame( 12, $row['duration_ms'] );
		$this->assertSame( 0, $runs->pending_id( 'updates.core' ) );
		$this->assertSame( $id, $runs->latest( 'updates.core' )['id'] );
		$this->assertArrayHasKey( 'updates.core', $runs->latest_per_check() );
	}

	public function test_runs_query_filters_and_counts(): void {
		$runs = new RunsRepository();
		$runs->finish( $runs->create_running( 'a.one', 'cron' ), 'pass', '', [], 1 );
		$runs->finish( $runs->create_running( 'a.one', 'cli' ), 'fail', '', [], 1 );
		$runs->finish( $runs->create_running( 'b.two', 'cron' ), 'pass', '', [], 1 );

		$this->assertSame( 3, $runs->query()['total'] );
		$this->assertSame( 2, $runs->query( [ 'check_id' => 'a.one' ] )['total'] );
		$this->assertSame( 1, $runs->query( [ 'trigger' => 'cli' ] )['total'] );
		$this->assertCount( 1, $runs->query( [ 'per_page' => 1 ] )['items'] );
	}

	public function test_stale_running_rows_become_errors(): void {
		global $wpdb;

		$runs = new RunsRepository();
		$id   = $runs->create_running( 'a.one', 'cron' );
		$wpdb->update( $runs->table(), [ 'started_at' => RunsRepository::now( time() - 3600 ) ], [ 'id' => $id ] ); // phpcs:ignore WordPress.DB

		$this->assertSame( 1, $runs->fail_stale( 600 ) );
		$this->assertSame( 'error', $runs->find( $id )['status'] );
	}

	public function test_findings_insert_touch_resolve_status(): void {
		$repo = new FindingsRepository();
		$id   = $repo->insert_finding( new Finding( 'k1', Severity::HIGH, 'Title' ), 'a.one', 5 );

		$this->assertArrayHasKey( 'k1', $repo->unresolved_for_check( 'a.one' ) );
		$this->assertSame( 1, $repo->open_counts_by_severity()['high'] );

		$repo->touch( $id, new Finding( 'k1', Severity::CRITICAL, 'New title' ), 6 );
		$row = $repo->find( $id );
		$this->assertSame( 'critical', $row['severity'] );
		$this->assertSame( 6, $row['run_id'] );

		$this->assertTrue( $repo->set_status( $id, 'accepted', 'Vendor patch pending' ) );
		$this->assertSame( 0, $repo->open_counts_by_severity()['critical'], 'Accepted findings are not open' );
		$this->assertSame( 1, $repo->query( [ 'status' => 'unresolved' ] )['total'] );

		$repo->resolve( [ $id ] );
		$this->assertSame( [], $repo->unresolved_for_check( 'a.one' ) );
		$this->assertNotNull( $repo->find( $id )['resolved_at'] );
		$this->assertFalse( $repo->set_status( $id, 'bogus' ) );
	}

	public function test_events_upsert_increments_the_hourly_row(): void {
		$events = new EventsRepository();
		$hour   = EventsRepository::hour();
		$row    = [
			'type'      => 'warning',
			'signature' => md5( 'x' ),
			'message'   => 'Undefined index',
			'source'    => 'plugin:foo',
			'hour'      => $hour,
			'count'     => 3,
		];

		$this->assertTrue( $events->upsert_many( [ $row ] ) );
		$this->assertTrue( $events->upsert_many( [ array_merge( $row, [ 'count' => 2 ] ) ] ) );

		$grouped = $events->grouped();
		$this->assertCount( 1, $grouped );
		$this->assertSame( 5, $grouped[0]['count'] );

		$counts = $events->counts_by_type( gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) );
		$this->assertSame( 5, $counts['warning'] );
		$this->assertSame( 0, $counts['fatal'] );

		$spark = $events->daily_counts( md5( 'x' ), 7 );
		$this->assertCount( 7, $spark );
		$this->assertSame( 5, array_sum( $spark ) );
	}

	public function test_queries_upsert_merges_weighted_average_and_max(): void {
		$queries = new QueriesRepository();
		$base    = [
			'signature' => md5( 'q' ),
			'caller'    => 'WP_Query->get_posts',
			'hour'      => EventsRepository::hour(),
		];

		$queries->upsert_many(
			[
				$base + [
					'count'  => 1,
					'max_ms' => 100.0,
					'avg_ms' => 100.0,
				],
			]
		);
		$queries->upsert_many(
			[
				$base + [
					'count'  => 3,
					'max_ms' => 300.0,
					'avg_ms' => 200.0,
				],
			]
		);

		$row = $queries->grouped( gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) )[0];
		$this->assertSame( 4, $row['count'] );
		$this->assertEqualsWithDelta( 300.0, (float) $row['max_ms'], 0.01 );
		$this->assertEqualsWithDelta( 175.0, (float) $row['avg_ms'], 0.01 );
	}

	public function test_changes_insert_and_query(): void {
		$changes = new ChangesRepository();
		$changes->insert(
			[
				'type'        => 'plugin',
				'action'      => 'update',
				'item'        => 'akismet',
				'item_name'   => 'Akismet',
				'old_version' => '5.0',
				'new_version' => '5.1',
				'source'      => 'deploy',
			]
		);
		$changes->insert(
			[
				'type'   => 'theme',
				'action' => 'activate',
				'item'   => 'twentytwentyfive',
				'source' => 'nonsense',
			]
		);

		$this->assertSame( 2, $changes->query()['total'] );
		$this->assertSame( 'deploy', $changes->query( [ 'type' => 'plugin' ] )['items'][0]['source'] );
		$this->assertSame( 'admin', $changes->query( [ 'type' => 'theme' ] )['items'][0]['source'], 'Unknown source falls back to admin' );
	}

	public function test_snapshots_latest(): void {
		$snapshots = new SnapshotsRepository();
		$snapshots->insert( 'plugins', [ 'v' => 1 ] );
		$snapshots->insert( 'plugins', [ 'v' => 2 ] );

		$this->assertSame( [ 'v' => 2 ], $snapshots->latest( 'plugins' )['data'] );
		$this->assertNull( $snapshots->latest( 'themes' ) );
	}
}
