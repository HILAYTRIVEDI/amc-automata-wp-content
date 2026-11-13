<?php
/**
 * Tests for the Dashboard widget.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Admin;

use AMC\Admin\DashboardWidget;
use AMC\Checks\Result;
use AMC\Core\Capabilities;
use AMC\Tests\Support\FakeCheck;
use AMC\Tests\TestCase;
use WP_REST_Server;

/**
 * Class DashboardWidgetTest
 */
final class DashboardWidgetTest extends TestCase {

	/**
	 * How many times the overview route was dispatched.
	 *
	 * @var int
	 */
	private int $dispatches = 0;

	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wp_add_dashboard_widget' ) ) {
			require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		}

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		set_current_screen( 'dashboard' );
		$GLOBALS['wp_meta_boxes'] = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test isolation.
		delete_transient( DashboardWidget::TRANSIENT . get_current_blog_id() );

		$this->dispatches = 0;
		add_filter(
			'rest_pre_dispatch',
			function ( $result, $server, $request ) {
				if ( '/amc/v1/overview' === $request->get_route() ) {
					++$this->dispatches;
				}
				return $result;
			},
			10,
			3
		);
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	private function engineer(): void {
		$user = self::factory()->user->create( [ 'role' => 'editor' ] );
		Capabilities::grant( $user );
		wp_set_current_user( $user );
	}

	private function render(): string {
		ob_start();
		( new DashboardWidget() )->render();

		return (string) ob_get_clean();
	}

	public function test_hooks_are_registered(): void {
		$widget = new DashboardWidget();
		$widget->register_hooks();

		$this->assertNotFalse( has_action( 'wp_dashboard_setup', [ $widget, 'register' ] ) );
		$this->assertNotFalse( has_action( 'amc_run_completed', [ $widget, 'flush' ] ) );
	}

	public function test_widget_is_added_for_engineers(): void {
		$this->engineer();

		( new DashboardWidget() )->register();

		$this->assertArrayHasKey( DashboardWidget::WIDGET_ID, $GLOBALS['wp_meta_boxes']['dashboard']['normal']['core'] );
	}

	public function test_widget_is_not_added_for_other_users(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		( new DashboardWidget() )->register();

		$this->assertArrayNotHasKey( 'dashboard', $GLOBALS['wp_meta_boxes'] );
	}

	public function test_renders_status_as_icon_and_text_with_severity_table_and_link(): void {
		$this->engineer();
		$this->register_checks( new FakeCheck( 'test.widget', Result::fail( 'Something is wrong.' ) ) );
		( new \AMC\Runner\Runner() )->run( 'test.widget', 'cli' );

		$html = $this->render();

		$this->assertStringContainsString( 'dashicons-dismiss', $html, 'An icon, not only colour.' );
		$this->assertStringContainsString( 'Failed', $html, 'And a text label.' );
		$this->assertStringContainsString( 'Open findings by severity', $html );
		$this->assertStringContainsString( 'Critical', $html );
		$this->assertStringContainsString( 'page=amc-copilot', $html );
		$this->assertStringContainsString( 'Last check ran', $html );
	}

	public function test_it_costs_one_cached_rest_call(): void {
		$this->engineer();

		$this->render();
		$this->render();
		$this->render();

		$this->assertSame( 1, $this->dispatches, 'The overview is dispatched once and then served from the transient.' );
		$this->assertIsArray( get_transient( DashboardWidget::TRANSIENT . get_current_blog_id() ) );
	}

	public function test_a_finished_run_clears_the_cache(): void {
		$this->engineer();
		( new DashboardWidget() )->register_hooks();

		$this->render();
		do_action( 'amc_run_completed', [] );
		$this->render();

		$this->assertSame( 2, $this->dispatches );
	}

	public function test_a_failed_request_shows_a_message_and_is_not_cached(): void {
		$this->engineer();
		add_filter(
			'rest_pre_dispatch',
			static fn () => new \WP_Error( 'amc_boom', 'Boom', [ 'status' => 500 ] ),
			5
		);

		$html = $this->render();

		$this->assertStringContainsString( 'could not be loaded', $html );
		$this->assertStringContainsString( 'Open the console', $html );
		$this->assertFalse( get_transient( DashboardWidget::TRANSIENT . get_current_blog_id() ) );
	}

	public function test_the_kill_switch_is_called_out(): void {
		$this->engineer();
		\AMC\Core\Settings::update( [ 'kill_switch' => true ] );

		$this->assertStringContainsString( 'kill switch is on', $this->render() );
	}
}
