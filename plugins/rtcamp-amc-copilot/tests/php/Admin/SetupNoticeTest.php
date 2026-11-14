<?php
/**
 * Tests for the "AMC Copilot is not set up yet" notice.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Admin;

use AMC\Admin\ConsolePage;
use AMC\Admin\SetupNotice;
use AMC\Admin\SetupState;
use AMC\Core\Capabilities;
use AMC\Tests\TestCase;

/**
 * Class SetupNoticeTest
 */
final class SetupNoticeTest extends TestCase {

	/**
	 * Engineer administrator.
	 *
	 * @var int
	 */
	private int $user;

	public function set_up(): void {
		parent::set_up();

		delete_option( SetupState::OPTION );

		$this->user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		Capabilities::grant( $this->user );
		wp_set_current_user( $this->user );
	}

	public function tear_down(): void {
		unset( $_GET['screen'] );
		set_current_screen( 'front' );

		parent::tear_down();
	}

	public function test_shows_on_the_plugins_screen_and_the_console(): void {
		$notice = new SetupNotice();

		$this->assertTrue( $notice->should_show( 'plugins' ) );
		$this->assertTrue( $notice->should_show( ConsolePage::hook_suffix() ) );
	}

	public function test_not_on_other_screens(): void {
		$notice = new SetupNotice();

		$this->assertFalse( $notice->should_show( 'dashboard' ) );
		$this->assertFalse( $notice->should_show( 'edit-post' ) );
	}

	public function test_not_on_the_wizard_itself(): void {
		$_GET['screen'] = 'setup';

		$this->assertFalse( ( new SetupNotice() )->should_show( ConsolePage::hook_suffix() ) );
		$this->assertTrue( ( new SetupNotice() )->should_show( 'plugins' ), 'The query arg only matters on the console.' );
	}

	public function test_not_once_setup_is_completed(): void {
		SetupState::complete( $this->user );

		$this->assertFalse( ( new SetupNotice() )->should_show( 'plugins' ) );
	}

	public function test_not_after_the_user_dismissed_it(): void {
		SetupState::dismiss( $this->user );

		$this->assertFalse( ( new SetupNotice() )->should_show( 'plugins' ) );
	}

	public function test_not_for_users_who_cannot_run_the_wizard(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertFalse( ( new SetupNotice() )->should_show( 'plugins' ) );
	}

	public function test_render_prints_a_dismissible_core_info_notice_linking_to_the_wizard(): void {
		set_current_screen( 'plugins' );

		ob_start();
		( new SetupNotice() )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'class="notice notice-info is-dismissible amc-setup-notice"', $html );
		$this->assertStringContainsString( esc_url( ConsolePage::url( 'setup' ) ), $html );
		$this->assertStringContainsString( 'Run the setup wizard', $html );
	}

	public function test_render_prints_nothing_where_it_does_not_show(): void {
		set_current_screen( 'dashboard' );

		ob_start();
		( new SetupNotice() )->render();

		$this->assertSame( '', ob_get_clean() );
	}
}
