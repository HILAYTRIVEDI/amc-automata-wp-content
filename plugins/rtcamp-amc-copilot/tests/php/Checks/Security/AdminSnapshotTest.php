<?php
/**
 * Tests for the security.admins check.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Checks\Security;

use AMC\Checks\Context;
use AMC\Checks\Group;
use AMC\Checks\Schedule;
use AMC\Checks\Security\AdminSnapshot;
use AMC\Checks\Severity;
use AMC\Checks\Status;
use AMC\Runner\ProcessedFindings;
use AMC\Runner\Runner;
use AMC\Storage\SnapshotsRepository;
use AMC\Tests\TestCase;

/**
 * Class AdminSnapshotTest
 */
final class AdminSnapshotTest extends TestCase {

	/**
	 * The "database" snapshot (null = none yet).
	 *
	 * @var array{data: array<string, mixed>, taken_at: string}|null
	 */
	private ?array $snapshot = null;

	/**
	 * Snapshots written.
	 *
	 * @var list<list<array<string, mixed>>>
	 */
	private array $written = [];

	/**
	 * User IDs that still exist.
	 *
	 * @var list<int>
	 */
	private array $existing = [];

	/**
	 * An administrator row.
	 *
	 * @param int $id ID.
	 *
	 * @return array{id: int, login: string, email: string, display_name: string}
	 */
	private function admin( int $id ): array {
		return [
			'id'           => $id,
			'login'        => 'admin' . $id,
			'email'        => 'admin' . $id . '@example.test',
			'display_name' => 'Admin ' . $id,
		];
	}

	/**
	 * Build the check with the given current admins.
	 *
	 * @param list<int> $ids Current administrator IDs.
	 */
	private function check( array $ids ): AdminSnapshot {
		return new AdminSnapshot(
			fn (): array => array_map( fn ( int $id ): array => $this->admin( $id ), $ids ),
			fn (): ?array => $this->snapshot,
			function ( array $admins ): void {
				$this->written[] = $admins;
				$this->snapshot  = [
					'data'     => [ 'admins' => $admins ],
					'taken_at' => '2026-11-13 09:00:00',
				];
			},
			fn ( int $id ): bool => in_array( $id, $this->existing, true )
		);
	}

	public function test_metadata(): void {
		$check = new AdminSnapshot();

		$this->assertSame( 'security.admins', $check->id() );
		$this->assertSame( Group::SECURITY, $check->group() );
		$this->assertSame( Schedule::DAILY, $check->schedule() );
		$this->assertNotSame( '', $check->label() );
	}

	public function test_first_run_records_a_baseline_without_findings(): void {
		$result = $this->check( [ 1, 2 ] )->run( Context::for_tests() );

		$this->assertSame( Status::INFO, $result->status );
		$this->assertTrue( $result->data['baseline'] );
		$this->assertSame( [], $result->findings );
		$this->assertCount( 1, $this->written );
		$this->assertSame( 2, $result->data['count'] );
	}

	public function test_no_change_passes_and_does_not_store_another_snapshot(): void {
		$this->check( [ 1, 2 ] )->run( Context::for_tests() );
		$result = $this->check( [ 1, 2 ] )->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( [], $result->findings );
		$this->assertCount( 1, $this->written );
		$this->assertFalse( $result->data['baseline'] );
	}

	public function test_an_added_administrator_is_a_high_finding_with_change_added(): void {
		$this->check( [ 1 ] )->run( Context::for_tests() );
		$result = $this->check( [ 1, 7 ] )->run( Context::for_tests() );

		$this->assertSame( Status::FAIL, $result->status );
		$this->assertCount( 1, $result->findings );

		$finding = $result->findings[0];
		$this->assertSame( 'admin:added:7', $finding->dedupe_key );
		$this->assertSame( Severity::HIGH, $finding->severity );
		$this->assertSame( 'added', $finding->data['change'] );
		$this->assertSame( 7, $finding->data['user_id'] );
		$this->assertSame( 'admin7', $finding->data['login'] );
		$this->assertCount( 2, $this->written, 'The changed list is stored as the new snapshot' );
	}

	public function test_a_deleted_administrator_is_removed_and_a_demoted_one_is_role_changed(): void {
		$this->check( [ 1, 2, 3 ] )->run( Context::for_tests() );
		$this->existing = [ 3 ]; // 2 was deleted; 3 still exists (demoted).

		$result = $this->check( [ 1 ] )->run( Context::for_tests() );
		$by_id  = array_column( $result->data['changes'], 'change', 'user_id' );

		$this->assertSame(
			[
				2 => 'removed',
				3 => 'role_changed',
			],
			$by_id
		);
		$this->assertSame( Status::WARN, $result->status, 'No admin was added' );
		$this->assertSame( Severity::LOW, $result->findings[0]->severity );
		$this->assertSame( Severity::MEDIUM, $result->findings[1]->severity );
		$this->assertSame( 'admin:role_changed:3', $result->findings[1]->dedupe_key );
	}

	public function test_default_admins_come_from_the_administrator_role(): void {
		$admin  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );

		$ids = array_column( AdminSnapshot::current_admins(), 'id' );

		$this->assertContains( $admin, $ids );
		$this->assertNotContains( $editor, $ids );
	}

	public function test_default_snapshot_storage_round_trips_and_an_added_admin_alerts_once(): void {
		$processed = [];
		add_action(
			'amc_findings_processed',
			static function ( ProcessedFindings $p ) use ( &$processed ): void {
				$processed[] = $p;
			}
		);

		$first = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->register_checks( new AdminSnapshot() );
		$runner = new Runner();

		$runner->run( 'security.admins' );
		$this->assertNotNull( ( new SnapshotsRepository() )->latest( 'admins' ) );
		$this->assertCount( 0, $processed[0]->new );

		$second = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$runner->run( 'security.admins' );
		$this->assertCount( 1, $processed[1]->new );
		$this->assertSame( 'added', $processed[1]->new[0]['data']['change'] );
		$this->assertSame( $second, $processed[1]->new[0]['data']['user_id'] );

		$runner->run( 'security.admins' );
		$this->assertCount( 0, $processed[2]->new, 'Not alerted again' );
		$this->assertCount( 1, $processed[2]->resolved, 'The change is resolved once the snapshot caught up' );
		$this->assertNotSame( $first, $second );
	}
}
