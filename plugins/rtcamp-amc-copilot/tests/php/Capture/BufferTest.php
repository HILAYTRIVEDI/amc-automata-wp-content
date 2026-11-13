<?php
/**
 * Tests for the capture buffer: aggregation, upsert, cap, kill switch.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Capture;

use AMC\Capture\Buffer;
use AMC\Capture\Signature;
use AMC\Core\Settings;
use AMC\Storage\EventsRepository;
use AMC\Storage\QueriesRepository;
use AMC\Tests\TestCase;

/**
 * Class BufferTest
 */
final class BufferTest extends TestCase {

	/**
	 * Events of this test only (by file).
	 *
	 * @param string $file Relative file.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function stored( string $file ): array {
		$rows = ( new EventsRepository() )->grouped( [ 'limit' => 500 ] );

		return array_values( array_filter( $rows, static fn ( array $row ): bool => $row['file'] === $file ) );
	}

	public function test_nothing_is_written_until_flush_and_flush_runs_one_upsert_per_table(): void {
		global $wpdb;
		$buffer = new Buffer();
		$before = $wpdb->num_queries;

		$buffer->add_event( 'warning', 'Undefined variable $a', WP_PLUGIN_DIR . '/buffer-a/a.php', 3 );
		$buffer->add_event( 'warning', 'Undefined variable $a', WP_PLUGIN_DIR . '/buffer-a/a.php', 3 );
		$buffer->add_query( 'SELECT * FROM x WHERE a = 1', 'caller', 150.0 );

		$this->assertSame( $before, $wpdb->num_queries, 'No query before flush' );

		$this->assertTrue( $buffer->drain() );

		$this->assertSame( $before + 3, $wpdb->num_queries, 'Kill switch option read, one upsert for events, one for queries' );
		$this->assertSame( 0, $buffer->size() );
	}

	public function test_events_are_aggregated_by_signature_and_hour_across_requests(): void {
		$file = WP_PLUGIN_DIR . '/buffer-b/b.php';

		$first = new Buffer();
		$first->add_event( 'warning', 'Undefined array key 4', $file, 9 );
		$first->add_event( 'warning', 'Undefined array key 4', $file, 9 );
		$first->add_event( 'warning', 'Undefined array key 7', $file, 9 );
		$first->drain();

		$second = new Buffer();
		$second->add_event( 'warning', 'Undefined array key 12', $file, 9 );
		$second->drain();

		$rows = array_values( array_filter( $this->stored( $this->relative( $file ) ), static fn ( array $row ): bool => 'warning' === $row['type'] ) );

		$this->assertCount( 1, $rows, 'Messages that differ only by numbers share a signature' );
		$this->assertSame( 4, $rows[0]['count'] );
		$this->assertSame( 'plugin:buffer-b', $rows[0]['source'] );
		$this->assertSame( 9, $rows[0]['line'] );
		$this->assertSame( Signature::of( 'warning', $this->relative( $file ), 9, 'Undefined array key 4' ), $rows[0]['signature'] );
	}

	public function test_slow_queries_are_merged_with_weighted_average_and_max(): void {
		$buffer = new Buffer();
		$buffer->add_query( 'SELECT * FROM t WHERE id = 1', 'Foo->bar', 150.0 );
		$buffer->add_query( 'SELECT * FROM t WHERE id = 1', 'Foo->bar', 250.0 );
		$buffer->add_query( 'SELECT * FROM t WHERE id = 2', 'Foo->bar', 500.0 );
		$buffer->drain();

		$rows = ( new QueriesRepository() )->grouped( gmdate( 'Y-m-d H:00:00', time() - HOUR_IN_SECONDS ) );
		$mine = array_values( array_filter( $rows, static fn ( array $row ): bool => 'Foo->bar' === $row['caller'] && str_contains( $row['sql_sample'], 'FROM t WHERE id = ?' ) ) );

		$this->assertCount( 1, $mine, 'Same normalised SQL and caller is one signature' );
		$this->assertSame( 3, $mine[0]['count'] );
		$this->assertEqualsWithDelta( 500.0, $mine[0]['max_ms'], 0.001 );
		$this->assertEqualsWithDelta( 300.0, $mine[0]['avg_ms'], 0.001 );
		$this->assertStringNotContainsString( '= 1', $mine[0]['sql_sample'], 'Literals are not stored' );
		$this->assertSame( md5( 'SELECT * FROM t WHERE id = ?|Foo->bar' ), $mine[0]['signature'] );
	}

	public function test_at_most_200_distinct_entries_and_the_rest_is_counted_as_dropped(): void {
		$buffer  = new Buffer();
		$dropped = [];
		add_action(
			'amc_capture_events_dropped',
			static function ( int $count ) use ( &$dropped ): void {
				$dropped[] = $count;
			}
		);

		for ( $i = 1; $i <= 250; $i++ ) {
			$buffer->add_event( 'notice', "message {$i}", '/cap/test.php', $i );
		}
		// Repeats of a buffered entry are never dropped.
		for ( $i = 0; $i < 20; $i++ ) {
			$buffer->add_event( 'notice', 'message 1', '/cap/test.php', 1 );
		}

		$this->assertSame( Buffer::MAX_ENTRIES, $buffer->size() );
		$this->assertSame( 50, $buffer->dropped() );
		$this->assertTrue( $buffer->is_full() );

		$buffer->drain();

		$rows = $this->stored( '/cap/test.php' );
		$this->assertCount( 200, $rows );
		$by_line = array_column( $rows, 'count', 'line' );
		$this->assertSame( 21, $by_line[1] );
		$this->assertSame( [ 50 ], $dropped );
		$this->assertSame( 0, $buffer->dropped(), 'The counter resets with the flush' );
	}

	public function test_events_and_queries_share_the_cap(): void {
		$buffer = new Buffer();
		for ( $i = 1; $i <= 150; $i++ ) {
			$buffer->add_event( 'warning', "w{$i}", '/cap/shared.php', $i );
		}
		for ( $i = 1; $i <= 100; $i++ ) {
			$buffer->add_query( "SELECT {$i} FROM t{$i}", 'c', 200.0 );
		}

		$this->assertSame( 200, $buffer->size() );
		$this->assertSame( 50, $buffer->dropped() );
	}

	public function test_kill_switch_discards_the_buffer_at_flush(): void {
		global $wpdb;
		Settings::update( [ 'kill_switch' => true ] );

		$buffer = new Buffer();
		$buffer->add_event( 'warning', 'dropped by kill switch', '/cap/killed.php', 1 );
		$before = $wpdb->num_queries;

		$this->assertFalse( $buffer->drain() );
		$this->assertSame( $before, $wpdb->num_queries, 'No write while the kill switch is on' );
		$this->assertSame( [], $this->stored( '/cap/killed.php' ) );
		$this->assertSame( 0, $buffer->size() );
	}

	public function test_an_empty_flush_reads_nothing(): void {
		global $wpdb;
		$before = $wpdb->num_queries;

		( new Buffer() )->flush();

		$this->assertSame( $before, $wpdb->num_queries, 'An empty buffer does not even read the kill switch option' );
	}

	public function test_constant_is_not_defined_in_the_suite(): void {
		$this->assertFalse( Buffer::disabled_by_constant() );
	}

	public function test_flush_is_registered_once_on_shutdown(): void {
		$buffer = new Buffer();
		$buffer->register_flush();
		$buffer->register_flush();

		$this->assertSame( 999, has_action( 'shutdown', [ $buffer, 'flush' ] ) );
	}

	/**
	 * Same value Buffer stores.
	 *
	 * @param string $file Absolute file.
	 */
	private function relative( string $file ): string {
		return Signature::relative_file( $file );
	}
}
