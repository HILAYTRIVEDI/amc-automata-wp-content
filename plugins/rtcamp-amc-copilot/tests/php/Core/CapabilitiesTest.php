<?php
/**
 * Tests for amc_manage / amc_view_reports.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Core;

use AMC\Core\Capabilities;
use AMC\Storage\ChangesRepository;
use AMC\Tests\TestCase;

/**
 * Class CapabilitiesTest
 */
final class CapabilitiesTest extends TestCase {

	public function test_activation_gives_administrators_view_reports(): void {
		get_role( 'administrator' )->remove_cap( Capabilities::VIEW_REPORTS );

		Capabilities::on_activation();

		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->assertTrue( user_can( $admin, Capabilities::VIEW_REPORTS ) );
	}

	public function test_grant_is_a_user_cap_and_logs_a_change(): void {
		$user  = self::factory()->user->create( [ 'role' => 'editor' ] );
		$actor = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$this->assertTrue( Capabilities::grant( $user, $actor ) );

		$this->assertTrue( user_can( $user, Capabilities::MANAGE ) );
		$this->assertSame( [ 'editor' ], get_userdata( $user )->roles, 'Role is unchanged' );

		$log = ( new ChangesRepository() )->query( [ 'action' => 'access_grant' ] );
		$this->assertSame( 1, $log['total'] );
		$this->assertSame( $actor, $log['items'][0]['user_id'] );
		$this->assertSame( 'user', $log['items'][0]['type'] );
	}

	public function test_revoke_removes_cap_and_logs(): void {
		$user = self::factory()->user->create();
		Capabilities::grant( $user );

		Capabilities::revoke( $user, 0, 'cli' );

		$this->assertFalse( user_can( $user, Capabilities::MANAGE ) );
		$log = ( new ChangesRepository() )->query( [ 'action' => 'access_revoke' ] );
		$this->assertSame( 'cli', $log['items'][0]['source'] );
	}

	public function test_unknown_user_is_an_error(): void {
		$this->assertWPError( Capabilities::grant( 999999 ) );
	}

	public function test_managing_access_needs_manage_options_too(): void {
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		$admin  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		Capabilities::grant( $editor );
		Capabilities::grant( $admin );

		$this->assertFalse( Capabilities::can_manage_access( $editor ) );
		$this->assertTrue( Capabilities::can_manage_access( $admin ) );
	}

	public function test_engineers_lists_only_users_with_the_cap(): void {
		$engineer = self::factory()->user->create();
		self::factory()->user->create();
		Capabilities::grant( $engineer );

		$ids = wp_list_pluck( Capabilities::engineers(), 'ID' );
		$this->assertSame( [ $engineer ], array_map( 'intval', $ids ) );
	}
}
