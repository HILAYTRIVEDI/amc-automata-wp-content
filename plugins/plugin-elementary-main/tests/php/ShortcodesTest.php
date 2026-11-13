<?php
/**
 * Tests for the Shortcodes module and the ExampleShortcode.
 *
 * Integration tests against real WordPress (wp-env): asserts the example
 * shortcode is wired into the Shortcodes module, registers its tag, and renders
 * through do_shortcode() with its default attributes merged.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Tests;

use AMC_Automata_Features\Features\Main;
use AMC_Automata_Features\Features\Modules\Shortcodes;
use AMC_Automata_Features\Features\Modules\Shortcodes\ExampleShortcode;
use ReflectionMethod;

/**
 * Class ShortcodesTest
 */
final class ShortcodesTest extends TestCase {

	/**
	 * The example shortcode's tag.
	 */
	private const TAG = 'amc_automata_features_example';

	/**
	 * Drop the shortcode if a test registered it.
	 */
	public function tear_down(): void {
		remove_shortcode( self::TAG );

		parent::tear_down();
	}

	/**
	 * get_tag() returns the exact shortcode tag.
	 */
	public function test_get_tag_returns_the_tag(): void {
		$this->assertSame( self::TAG, ExampleShortcode::get_tag() );
	}

	/**
	 * The shortcode extends the framework AbstractShortcode.
	 */
	public function test_extends_framework_abstract_shortcode(): void {
		$this->assertInstanceOf(
			'rtCamp\WPPrimitives\Contracts\Abstracts\AbstractShortcode',
			new ExampleShortcode()
		);
	}

	/**
	 * The Shortcodes module manages the ExampleShortcode class.
	 */
	public function test_module_lists_the_example_shortcode(): void {
		$method = new ReflectionMethod( Shortcodes::class, 'get_classes' );
		$method->setAccessible( true );

		$classes = $method->invoke( new Shortcodes() );

		$this->assertContains( ExampleShortcode::class, $classes );
	}

	/**
	 * The Shortcodes module is registered in Main (loaded as a Registrable module).
	 */
	public function test_module_registered_in_main(): void {
		$this->assertContains( Shortcodes::class, Main::CLASSES );
	}

	/**
	 * register_hooks() schedules shortcode registration on init.
	 */
	public function test_register_hooks_registers_shortcode_on_init(): void {
		$shortcode = new ExampleShortcode();
		$shortcode->register_hooks();

		$this->assertNotFalse( has_action( 'init', [ $shortcode, 'register_shortcode' ] ) );
	}

	/**
	 * register_shortcode() adds the tag to WordPress.
	 */
	public function test_register_shortcode_adds_the_tag(): void {
		( new ExampleShortcode() )->register_shortcode();

		$this->assertTrue( shortcode_exists( self::TAG ) );
	}

	/**
	 * do_shortcode() renders the default output without explicit attributes.
	 */
	public function test_renders_default_output(): void {
		( new ExampleShortcode() )->register_shortcode();

		$output = do_shortcode( '[' . self::TAG . ']' );

		$this->assertStringContainsString( 'amc-automata-features-example-shortcode', $output );
		// Default count is 5, so the plural item line is rendered.
		$this->assertStringContainsString( 'Showing 5 example items.', $output );
		// No title attribute by default, so no heading is emitted.
		$this->assertStringNotContainsString( '<h2>', $output );
	}

	/**
	 * Explicit attributes flow through to the rendered output.
	 */
	public function test_renders_with_explicit_attributes(): void {
		( new ExampleShortcode() )->register_shortcode();

		$output = do_shortcode( '[' . self::TAG . ' title="Hello" count="1"]' );

		$this->assertStringContainsString( '<h2>Hello</h2>', $output );
		// count=1 renders the singular item line.
		$this->assertStringContainsString( 'Showing 1 example item.', $output );
	}
}
