<?php
/**
 * Tests for the console admin page (menu + mount point).
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Admin;

use AMC\Admin\ConsolePage;
use AMC\Core\Capabilities;
use AMC\Tests\TestCase;

/**
 * Class ConsolePageTest
 */
final class ConsolePageTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'add_menu_page' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$GLOBALS['menu']              = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test isolation.
		$GLOBALS['_registered_pages'] = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test isolation.
	}

	/**
	 * Find the console's entry in the admin menu.
	 *
	 * @return array<int, mixed>|null Menu entry, or null.
	 */
	private function menu_entry(): ?array {
		foreach ( (array) $GLOBALS['menu'] as $entry ) {
			if ( ConsolePage::SLUG === $entry[2] ) {
				return $entry;
			}
		}

		return null;
	}

	public function test_menu_is_top_level_and_needs_amc_manage(): void {
		( new ConsolePage() )->register_page();

		$entry = $this->menu_entry();

		$this->assertNotNull( $entry, 'The AMC Copilot menu is registered.' );
		$this->assertSame( 'AMC Copilot', $entry[0] );
		$this->assertSame( Capabilities::MANAGE, $entry[1] );
	}

	public function test_engineers_can_open_the_page_and_others_cannot(): void {
		$engineer = self::factory()->user->create( [ 'role' => 'editor' ] );
		Capabilities::grant( $engineer );
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$this->assertTrue( user_can( $engineer, Capabilities::MANAGE ) );
		$this->assertFalse( user_can( $admin, Capabilities::MANAGE ), 'Administrators do not get console access by role.' );
	}

	public function test_hook_suffix_and_url(): void {
		$this->assertSame( 'toplevel_page_amc-copilot', ConsolePage::hook_suffix() );
		$this->assertStringEndsWith( 'admin.php?page=amc-copilot', ConsolePage::url() );
		$this->assertStringEndsWith( 'admin.php?page=amc-copilot&screen=updates', ConsolePage::url( 'updates' ) );
	}

	public function test_render_prints_the_react_mount_point(): void {
		ob_start();
		( new ConsolePage() )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="amc-console-root"', $html );
		$this->assertStringContainsString( '<noscript>', $html );
	}

	public function test_the_page_is_registered_through_the_admin_module(): void {
		$module  = new \AMC\Admin\AdminModule();
		$method  = new \ReflectionMethod( $module, 'get_classes' );
		$classes = $method->invoke( $module );

		$this->assertContains( ConsolePage::class, $classes );
		$this->assertContains( \AMC\Admin\ConsoleAssets::class, $classes );
		$this->assertContains( \AMC\Admin\DashboardWidget::class, $classes );
	}
}
