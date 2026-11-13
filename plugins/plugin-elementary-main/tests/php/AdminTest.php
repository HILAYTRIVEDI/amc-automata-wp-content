<?php
/**
 * Tests for the Admin module and its example admin page.
 *
 * Runs under real WordPress (wp-env): register_page() actually registers the
 * submenu with WordPress' admin-menu globals, so assertions read those globals.
 * add_submenu_page() gates on the current user's capability, so an
 * administrator is set up in set_up().
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Tests;

use AMC_Automata_Features\Features\Modules\Admin;
use AMC_Automata_Features\Features\Modules\Admin\ExampleAdminPage;
use ReflectionMethod;

/**
 * Class AdminTest
 */
final class AdminTest extends TestCase {

	/**
	 * Page slug the example admin page registers under.
	 */
	private const SLUG = 'amc-automata-features-features-tools';

	/**
	 * Parent menu the example admin page registers beneath.
	 */
	private const PARENT = 'tools.php';

	/**
	 * Admin pages register only in admin context and gate on capability, so
	 * set up an administrator and clean admin-menu globals.
	 */
	public function set_up(): void {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$GLOBALS['menu']             = [];
		$GLOBALS['submenu']          = [];
		$GLOBALS['admin_page_hooks'] = [];
	}

	/**
	 * Read the module's protected get_classes() list.
	 *
	 * @return array<int, class-string>
	 */
	private function module_classes(): array {
		$method = new ReflectionMethod( Admin::class, 'get_classes' );
		$method->setAccessible( true );

		return $method->invoke( new Admin() );
	}

	/**
	 * The example admin page is listed in the module's class list.
	 */
	public function test_example_is_listed_in_get_classes(): void {
		$this->assertContains( ExampleAdminPage::class, $this->module_classes() );
	}

	/**
	 * The example is the expected concrete AbstractAdminPage subtype.
	 */
	public function test_example_is_admin_page_instance(): void {
		$this->assertInstanceOf(
			'rtCamp\WPPrimitives\Contracts\Abstracts\AbstractAdminPage',
			new ExampleAdminPage()
		);
	}

	/**
	 * The static get_slug() returns the documented slug without an instance.
	 */
	public function test_static_get_slug_is_accessible(): void {
		$this->assertSame( self::SLUG, ExampleAdminPage::get_slug() );
	}

	/**
	 * register_hooks() wires the admin menu action.
	 */
	public function test_register_hooks_wires_admin_menu(): void {
		$page = new ExampleAdminPage();
		$page->register_hooks();

		$this->assertNotFalse( has_action( 'admin_menu', [ $page, 'register_page' ] ) );
	}

	/**
	 * register_page() adds the page as a submenu under Tools.
	 */
	public function test_register_page_adds_submenu_under_tools(): void {
		( new ExampleAdminPage() )->register_page();

		$slugs = wp_list_pluck( $GLOBALS['submenu'][ self::PARENT ] ?? [], 2 );
		$this->assertContains( self::SLUG, $slugs );
	}

	/**
	 * render() echoes non-empty page markup.
	 */
	public function test_render_outputs_non_empty_markup(): void {
		ob_start();
		( new ExampleAdminPage() )->render();
		$html = (string) ob_get_clean();

		$this->assertNotEmpty( $html );
		$this->assertStringContainsString( '<div class="wrap">', $html );
	}
}
