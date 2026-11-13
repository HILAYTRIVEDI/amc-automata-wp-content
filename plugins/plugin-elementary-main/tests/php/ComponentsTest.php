<?php
/**
 * Tests for the Core Components loader.
 *
 * Integration tests against real WordPress (wp-env): asserts the plugin's
 * component loader extends the framework ComponentLoader, is shareable, is
 * registered in Main, and exposes a sane context slug. The plugin ships no
 * component partials of its own, so rendering is exercised only when a
 * component file actually exists.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Tests;

use AMC_Automata_Features\Features\Core\Components;
use AMC_Automata_Features\Features\Main;
use ReflectionMethod;

/**
 * Class ComponentsTest
 */
final class ComponentsTest extends TestCase {

	/**
	 * The class extends the framework ComponentLoader.
	 */
	public function test_extends_framework_component_loader(): void {
		$this->assertInstanceOf( 'rtCamp\WPPrimitives\ComponentLoader', new Components() );
	}

	/**
	 * It is shareable, so the container hands out a single instance.
	 */
	public function test_is_shareable(): void {
		$this->assertInstanceOf( 'rtCamp\WPPrimitives\Contracts\Interfaces\Shareable', new Components() );
	}

	/**
	 * It is registered in Main and resolvable from the container.
	 */
	public function test_registered_and_shared_in_main(): void {
		$this->assertContains( Components::class, Main::CLASSES );
		$this->assertInstanceOf( Components::class, Main::get_instance()->get_shared( Components::class ) );
	}

	/**
	 * The shared resolution returns the very same instance every time.
	 */
	public function test_shared_resolves_to_a_single_instance(): void {
		$first  = Main::get_instance()->get_shared( Components::class );
		$second = Main::get_instance()->get_shared( Components::class );

		$this->assertSame( $first, $second );
	}

	/**
	 * The context slug namespaces the plugin's component asset handles.
	 */
	public function test_context_is_the_plugin_slug(): void {
		$method = new ReflectionMethod( Components::class, 'get_context' );
		$method->setAccessible( true );

		$this->assertSame( 'amc-automata-features-features', $method->invoke( new Components() ) );
	}
}
