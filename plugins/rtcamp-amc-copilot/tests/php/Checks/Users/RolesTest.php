<?php
/**
 * Tests for the users.roles check.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Checks\Users;

use AMC\Checks\Context;
use AMC\Checks\Group;
use AMC\Checks\Schedule;
use AMC\Checks\Severity;
use AMC\Checks\Status;
use AMC\Checks\Users\Roles;
use AMC\Storage\SnapshotsRepository;
use AMC\Tests\TestCase;

/**
 * Class RolesTest
 */
final class RolesTest extends TestCase {

	/**
	 * Build the check around fixture counts and roles.
	 *
	 * @param string $counts Fixture name in fixtures/users-roles/.
	 * @param string $roles  Fixture name in fixtures/users-roles/.
	 */
	private function check_with( string $counts, string $roles = 'roles' ): Roles {
		return new Roles(
			fn (): array => $this->fixture( 'users-roles/' . $counts ),
			fn (): array => $this->fixture( 'users-roles/' . $roles )
		);
	}

	public function test_metadata(): void {
		$check = new Roles();

		$this->assertSame( 'users.roles', $check->id() );
		$this->assertSame( Group::USERS, $check->group() );
		$this->assertSame( Schedule::DAILY, $check->schedule() );
		$this->assertNotSame( '', $check->label() );
	}

	public function test_the_first_run_records_a_baseline_without_findings(): void {
		$result = $this->check_with( 'counts' )->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( [], $result->findings );
		$this->assertSame( [ 'counts', 'total', 'roles', 'changes' ], array_keys( $result->data ) );
		$this->assertSame(
			[
				'administrator' => 1,
				'editor'        => 2,
				'subscriber'    => 2,
			],
			$result->data['counts']
		);
		$this->assertSame( 5, $result->data['total'] );
		$this->assertSame( [], $result->data['changes'] );
		$this->assertSame(
			[
				[
					'name'         => 'administrator',
					'capabilities' => [ 'manage_options', 'read' ],
				],
				[
					'name'         => 'editor',
					'capabilities' => [ 'edit_posts', 'read' ],
				],
				[
					'name'         => 'subscriber',
					'capabilities' => [ 'read' ],
				],
			],
			$result->data['roles']
		);

		$snapshot = ( new SnapshotsRepository() )->latest( 'roles' );
		$this->assertNotNull( $snapshot );
		$this->assertCount( 3, $snapshot['data']['roles'] );
	}

	public function test_an_unchanged_second_run_passes_with_no_changes(): void {
		$this->check_with( 'counts' )->run( Context::for_tests() );
		$result = $this->check_with( 'counts' )->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( [], $result->findings );
		$this->assertSame( [], $result->data['changes'] );
	}

	public function test_capability_changes_warn_with_one_finding_per_role(): void {
		$this->check_with( 'counts' )->run( Context::for_tests() );
		$result = $this->check_with( 'counts', 'roles-changed' )->run( Context::for_tests() );

		$this->assertSame( Status::WARN, $result->status );
		$this->assertSame( 'Role capabilities changed since the last run.', $result->summary );
		$this->assertCount( 2, $result->findings );

		$this->assertSame(
			[
				[
					'role'    => 'administrator',
					'added'   => [ 'edit_theme_options' ],
					'removed' => [],
				],
				[
					'role'    => 'editor',
					'added'   => [ 'publish_posts' ],
					'removed' => [ 'edit_posts' ],
				],
			],
			$result->data['changes']
		);

		$administrator = $result->findings[0];
		$this->assertSame( 'role:administrator:caps', $administrator->dedupe_key );
		$this->assertSame( Severity::MEDIUM, $administrator->severity );
		$this->assertSame( $result->data['changes'][0], $administrator->data );

		$editor = $result->findings[1];
		$this->assertSame( 'role:editor:caps', $editor->dedupe_key );
		$this->assertSame( Severity::MEDIUM, $editor->severity );
		$this->assertSame( $result->data['changes'][1], $editor->data );
	}

	public function test_the_total_falls_back_to_the_role_sum(): void {
		$result = $this->check_with( 'counts-no-total' )->run( Context::for_tests() );

		$this->assertSame( 5, $result->data['total'] );
	}

	public function test_no_roles_and_no_users_is_empty(): void {
		$check = new Roles( static fn (): array => [], static fn (): array => [] );

		$result = $check->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( 0, $result->data['total'] );
		$this->assertSame( [], $result->data['counts'] );
		$this->assertSame( [], $result->data['roles'] );
	}
}
