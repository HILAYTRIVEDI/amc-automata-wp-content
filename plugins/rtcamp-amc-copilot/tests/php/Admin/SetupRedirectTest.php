<?php
/**
 * Tests for the one-time redirect to the setup wizard after activation.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Admin;

use AMC\Admin\ConsolePage;
use AMC\Admin\SetupRedirect;
use AMC\Admin\SetupState;
use AMC\Core\Capabilities;
use AMC\Tests\TestCase;

/**
 * Class SetupRedirectTest
 */
final class SetupRedirectTest extends TestCase {

	/**
	 * Engineer administrator who activates the plugin.
	 *
	 * @var int
	 */
	private int $user;

	public function set_up(): void {
		parent::set_up();

		delete_option( SetupState::OPTION );
		delete_transient( SetupState::REDIRECT_TRANSIENT );

		$this->user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		Capabilities::grant( $this->user );
		wp_set_current_user( $this->user );
		set_current_screen( 'dashboard' );
	}

	public function tear_down(): void {
		unset( $_GET['activate-multi'], $_REQUEST['action'] );
		set_current_screen( 'front' );

		parent::tear_down();
	}

	/**
	 * A redirect that never thinks it runs under WP-CLI.
	 */
	private function redirect(): SetupRedirect {
		return new SetupRedirect( static fn (): bool => false );
	}

	public function test_activation_queues_a_redirect_for_the_activating_user(): void {
		$this->redirect()->queue();

		$this->assertTrue( SetupRedirect::is_queued() );
		$this->assertSame( $this->user, (int) get_transient( SetupState::REDIRECT_TRANSIENT ) );
	}

	public function test_the_redirect_goes_to_the_wizard_once(): void {
		$redirect = $this->redirect();
		$redirect->queue();

		$url = $redirect->target();

		$this->assertSame( ConsolePage::url( 'setup' ), $url );
		$this->assertStringEndsWith( 'admin.php?page=amc-copilot&screen=setup', (string) $url );
		$this->assertFalse( get_transient( SetupState::REDIRECT_TRANSIENT ), 'The transient is consumed.' );
		$this->assertNull( $redirect->target(), 'Only once.' );
	}

	public function test_no_redirect_without_activation(): void {
		$this->assertNull( $this->redirect()->target() );
	}

	public function test_wp_cli_activation_queues_nothing(): void {
		$redirect = new SetupRedirect( static fn (): bool => true );

		$redirect->queue();

		$this->assertFalse( SetupRedirect::is_queued() );
	}

	public function test_bulk_activation_queues_nothing(): void {
		$_REQUEST['action'] = 'activate-selected';

		$this->redirect()->queue();

		$this->assertFalse( SetupRedirect::is_queued() );
	}

	public function test_bulk_activation_landing_page_skips_and_consumes_the_redirect(): void {
		$this->redirect()->queue();
		$_GET['activate-multi'] = 'true';

		$this->assertNull( $this->redirect()->target() );
		$this->assertFalse( get_transient( SetupState::REDIRECT_TRANSIENT ) );
	}

	public function test_ajax_requests_never_redirect(): void {
		$this->redirect()->queue();
		add_filter( 'wp_doing_ajax', '__return_true' );

		$this->assertNull( $this->redirect()->target() );
	}

	public function test_network_admin_never_redirects(): void {
		$this->redirect()->queue();
		set_current_screen( 'dashboard-network' );

		$this->assertNull( $this->redirect()->target() );
	}

	public function test_no_redirect_once_setup_is_completed(): void {
		$this->redirect()->queue();
		SetupState::complete( $this->user );

		$this->assertNull( $this->redirect()->target() );
	}

	public function test_activation_after_completion_queues_nothing(): void {
		SetupState::complete( $this->user );

		$this->redirect()->queue();

		$this->assertFalse( SetupRedirect::is_queued() );
	}

	public function test_only_the_activating_user_is_redirected(): void {
		$this->redirect()->queue();

		$other = self::factory()->user->create( [ 'role' => 'administrator' ] );
		Capabilities::grant( $other );
		wp_set_current_user( $other );

		$this->assertNull( $this->redirect()->target() );
	}

	public function test_a_user_who_cannot_run_the_wizard_is_not_redirected(): void {
		$this->redirect()->queue();
		Capabilities::revoke( $this->user );

		$this->assertNull( $this->redirect()->target() );
	}

	public function test_hooks_are_registered(): void {
		$redirect = $this->redirect();
		$redirect->register_hooks();

		$this->assertNotFalse( has_action( 'amc_activate', [ $redirect, 'queue' ] ) );
		$this->assertNotFalse( has_action( 'admin_init', [ $redirect, 'maybe_redirect' ] ) );
	}
}
