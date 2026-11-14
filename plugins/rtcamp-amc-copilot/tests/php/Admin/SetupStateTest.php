<?php
/**
 * Tests for the setup wizard state (completion option, notice dismissal).
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Admin;

use AMC\Admin\SetupState;
use AMC\Core\Capabilities;
use AMC\Tests\TestCase;

/**
 * Class SetupStateTest
 */
final class SetupStateTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		delete_option( SetupState::OPTION );
	}

	/**
	 * An engineer who is also an administrator.
	 */
	private function engineer_admin(): int {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		Capabilities::grant( $user );

		return $user;
	}

	public function test_not_completed_on_a_fresh_install(): void {
		$this->assertFalse( SetupState::is_completed() );
		$this->assertNull( SetupState::completed_at() );
		$this->assertFalse( SetupState::for_rest()['completed'] );
		$this->assertNull( SetupState::for_rest()['completed_at'] );
	}

	public function test_complete_stores_the_time_and_user_with_autoload_off(): void {
		global $wpdb;

		$user = $this->engineer_admin();
		$at   = SetupState::complete( $user );

		$this->assertTrue( SetupState::is_completed() );
		$this->assertSame( $at, SetupState::completed_at() );
		$this->assertSame( $user, get_option( SetupState::OPTION )['user_id'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string) SetupState::for_rest()['completed_at'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Asserting the stored autoload flag.
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", SetupState::OPTION ) );
		$this->assertContains( $autoload, [ 'off', 'no' ], 'The option is not autoloaded.' );
	}

	public function test_reset_forgets_completion(): void {
		SetupState::complete( 1 );
		SetupState::reset();

		$this->assertFalse( SetupState::is_completed() );
	}

	public function test_a_malformed_option_counts_as_not_completed(): void {
		update_option( SetupState::OPTION, 'yes', false );
		$this->assertFalse( SetupState::is_completed() );

		update_option( SetupState::OPTION, [ 'completed_at' => '' ], false );
		$this->assertFalse( SetupState::is_completed() );
	}

	public function test_running_the_wizard_needs_amc_manage_and_manage_options(): void {
		$admin  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		Capabilities::grant( $editor );

		$this->assertTrue( SetupState::can_run( $this->engineer_admin() ) );
		$this->assertFalse( SetupState::can_run( $admin ), 'An administrator without amc_manage is a client.' );
		$this->assertFalse( SetupState::can_run( $editor ), 'An engineer without manage_options cannot save Settings.' );
		$this->assertFalse( SetupState::can_run( 0 ), 'Nobody logged in.' );
	}

	public function test_dismissal_is_per_user(): void {
		$first  = $this->engineer_admin();
		$second = $this->engineer_admin();

		SetupState::dismiss( $first );

		$this->assertTrue( SetupState::is_dismissed( $first ) );
		$this->assertFalse( SetupState::is_dismissed( $second ) );
	}
}
