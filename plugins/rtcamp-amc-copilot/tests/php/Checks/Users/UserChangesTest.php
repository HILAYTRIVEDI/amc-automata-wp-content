<?php
/**
 * Tests for the users.changes check.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Checks\Users;

use AMC\Checks\Context;
use AMC\Checks\Group;
use AMC\Checks\Schedule;
use AMC\Checks\Status;
use AMC\Checks\Users\UserChanges;
use AMC\Storage\ChangesRepository;
use AMC\Tests\TestCase;

/**
 * Class UserChangesTest
 */
final class UserChangesTest extends TestCase {

	public function test_metadata(): void {
		$check = new UserChanges();

		$this->assertSame( 'users.changes', $check->id() );
		$this->assertSame( Group::USERS, $check->group() );
		$this->assertSame( Schedule::EVENT, $check->schedule() );
		$this->assertNotSame( '', $check->label() );
	}

	public function test_no_changes_passes_with_empty_data(): void {
		$check = new UserChanges(
			static fn (): array => [
				'items' => [],
				'total' => 0,
			]
		);

		$result = $check->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( [], $result->findings );
		$this->assertSame( [ 'total', 'items', 'counts' ], array_keys( $result->data ) );
		$this->assertSame( 0, $result->data['total'] );
		$this->assertSame( [], $result->data['items'] );
		$this->assertSame( [], $result->data['counts'] );
		$this->assertSame( '0 user changes recorded.', $result->summary );
	}

	public function test_rows_are_normalised_grouped_and_counted_by_action(): void {
		$result = ( new UserChanges( fn (): array => $this->fixture( 'users-changes/changes' ) ) )
			->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( 7, $result->data['total'], 'The window total, not the page size' );
		$this->assertSame( '7 user changes recorded.', $result->summary );
		$this->assertSame(
			[
				'role'   => 2,
				'delete' => 1,
			],
			$result->data['counts']
		);

		$this->assertSame(
			[
				[
					'id'         => 12,
					'action'     => 'role',
					'item'       => 'jane',
					'item_name'  => 'Jane Doe',
					'user_id'    => 4,
					'created_at' => '2026-11-13 10:00:00',
				],
				[
					'id'         => 11,
					'action'     => 'delete',
					'item'       => 'bob',
					'item_name'  => 'Bob Smith',
					'user_id'    => 7,
					'created_at' => '2026-11-12 09:00:00',
				],
				[
					'id'         => 10,
					'action'     => 'role',
					'item'       => 'sam',
					'item_name'  => 'Sam Lee',
					'user_id'    => 9,
					'created_at' => '2026-11-11 08:00:00',
				],
			],
			$result->data['items']
		);
	}

	public function test_a_missing_total_falls_back_to_the_page_count_and_rows_get_defaults(): void {
		$check = new UserChanges( static fn (): array => [ 'items' => [ [ 'action' => 'role' ] ] ] );

		$result = $check->run( Context::for_tests() );

		$this->assertSame( 1, $result->data['total'] );
		$this->assertSame( '1 user change recorded.', $result->summary );
		$this->assertSame(
			[
				'id'         => 0,
				'action'     => 'role',
				'item'       => '',
				'item_name'  => '',
				'user_id'    => 0,
				'created_at' => '',
			],
			$result->data['items'][0]
		);
	}

	public function test_the_default_source_reads_user_rows_from_the_change_log(): void {
		$changes = new ChangesRepository();
		$changes->insert(
			[
				'type'   => 'plugin',
				'action' => 'update',
				'item'   => 'akismet',
			]
		);
		$changes->insert(
			[
				'type'      => 'user',
				'action'    => 'role',
				'item'      => 'jane',
				'item_name' => 'Jane Doe',
			]
		);

		$result = ( new UserChanges() )->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( 1, $result->data['total'] );
		$this->assertSame( 'jane', $result->data['items'][0]['item'] );
		$this->assertSame( [ 'role' => 1 ], $result->data['counts'] );
	}
}
