<?php
/**
 * Tests for the Settings module and its example settings page.
 *
 * Runs under real WordPress (wp-env): register_settings() actually registers
 * the option via the Settings API and register_page() actually adds the
 * submenu, so assertions go through the WordPress globals these calls populate.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Tests;

use AMC_Automata_Features\Features\Modules\Settings;
use AMC_Automata_Features\Features\Modules\Settings\ExampleSettingsPage;
use ReflectionMethod;

/**
 * Class SettingsTest
 */
final class SettingsTest extends TestCase {

	/**
	 * Page slug the example settings page registers under.
	 */
	private const SLUG = 'amc-automata-features-features';

	/**
	 * The single option the example settings page registers.
	 */
	private const OPTION = 'amc_automata_features_features_example_text';

	/**
	 * Admin pages and the Settings API gate on admin context and capability,
	 * so set up an administrator and clean admin-menu globals.
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
	 * Drop the option and the registered setting so they do not leak.
	 */
	public function tear_down(): void {
		unregister_setting( self::SLUG, self::OPTION );
		delete_option( self::OPTION );

		parent::tear_down();
	}

	/**
	 * Read the module's protected get_classes() list.
	 *
	 * @return array<int, class-string>
	 */
	private function module_classes(): array {
		$method = new ReflectionMethod( Settings::class, 'get_classes' );
		$method->setAccessible( true );

		return $method->invoke( new Settings() );
	}

	/**
	 * The example settings page is listed in the module's class list.
	 */
	public function test_example_is_listed_in_get_classes(): void {
		$this->assertContains( ExampleSettingsPage::class, $this->module_classes() );
	}

	/**
	 * The example is the expected concrete AbstractSettingsPage subtype.
	 */
	public function test_example_is_settings_page_instance(): void {
		$this->assertInstanceOf(
			'rtCamp\WPPrimitives\Contracts\Abstracts\AbstractSettingsPage',
			new ExampleSettingsPage()
		);
	}

	/**
	 * The static get_slug() returns the documented slug without an instance.
	 */
	public function test_static_get_slug_is_accessible(): void {
		$this->assertSame( self::SLUG, ExampleSettingsPage::get_slug() );
	}

	/**
	 * register_hooks() wires the admin menu and admin init actions.
	 */
	public function test_register_hooks_wires_menu_and_settings(): void {
		$page = new ExampleSettingsPage();
		$page->register_hooks();

		$this->assertNotFalse( has_action( 'admin_menu', [ $page, 'register_page' ] ) );
		$this->assertNotFalse( has_action( 'admin_init', [ $page, 'register_settings' ] ) );
	}

	/**
	 * register_page() adds the page as a submenu under its parent (Settings).
	 */
	public function test_register_page_adds_submenu_under_settings(): void {
		( new ExampleSettingsPage() )->register_page();

		$slugs = wp_list_pluck( $GLOBALS['submenu']['options-general.php'] ?? [], 2 );
		$this->assertContains( self::SLUG, $slugs );
	}

	/**
	 * register_settings() registers the example option with the Settings API.
	 */
	public function test_register_settings_registers_the_option(): void {
		( new ExampleSettingsPage() )->register_settings();

		$this->assertArrayHasKey( self::OPTION, get_registered_settings() );
	}

	/**
	 * render() outputs the page wrapper markup.
	 */
	public function test_render_outputs_page_markup(): void {
		ob_start();
		( new ExampleSettingsPage() )->render();
		$html = (string) ob_get_clean();

		$this->assertNotEmpty( $html );
		$this->assertStringContainsString( '<form', $html );
	}
}
