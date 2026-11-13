<?php
/**
 * Tests for the Main plugin bootstrap class.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests;

use AMC\Core\PluginSetup;
use AMC\Main;

/**
 * Class MainTest
 */
final class MainTest extends TestCase {

	/**
	 * The bootstrap class autoloads under its FQN.
	 *
	 * Catches PSR-4 / namespace breakage at the cheapest possible level;
	 * if this fails, every other test in the suite is meaningless.
	 */
	public function test_main_class_exists(): void {
		$this->assertTrue( class_exists( Main::class ) );
	}

	/**
	 * get_instance() returns a Main and is a true singleton.
	 */
	public function test_get_instance_returns_singleton(): void {
		$first  = Main::get_instance();
		$second = Main::get_instance();

		$this->assertInstanceOf( Main::class, $first );
		$this->assertSame( $first, $second );
	}

	/**
	 * activate() persists the plugin version option.
	 */
	public function test_activate_persists_plugin_version(): void {
		delete_option( 'amc_version' );

		$setup = new PluginSetup();
		$setup->activate();

		$this->assertSame(
			AMC_COPILOT_VERSION,
			get_option( 'amc_version' )
		);
	}

	/**
	 * register_hooks() wires the always-on Core hooks (textdomain on init), and
	 * deactivate() stays callable for any capability set.
	 *
	 * The example-cron unschedule path is exercised by the cron capability's own
	 * test, which ships and is removed with that capability; Core stays decoupled
	 * from any example.
	 */
	public function test_register_hooks_wires_core_hooks(): void {
		$setup = new PluginSetup();
		$setup->register_hooks();
		$setup->deactivate();

		$this->assertNotFalse( has_action( 'init', [ $setup, 'load_textdomain' ] ) );
	}

	/**
	 * Every module class-string referenced from Main::CLASSES actually exists.
	 *
	 * If someone renames a module file without updating Main, this catches it.
	 */
	public function test_every_loaded_class_exists(): void {
		foreach ( Main::CLASSES as $class ) {
			$this->assertTrue( class_exists( $class ), "Missing class: {$class}" );
		}
	}
}
