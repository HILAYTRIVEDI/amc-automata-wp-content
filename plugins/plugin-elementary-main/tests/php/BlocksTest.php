<?php
/**
 * Tests for the Blocks module and the ExampleDynamicBlock.
 *
 * Integration tests against real WordPress (wp-env): asserts the example block
 * is wired into the Blocks module, registers its init hook, and renders to a
 * string through its template.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Tests;

use AMC_Automata_Features\Features\Main;
use AMC_Automata_Features\Features\Modules\Blocks;
use AMC_Automata_Features\Features\Modules\Blocks\ExampleDynamicBlock;
use ReflectionMethod;
use WP_Block_Type_Registry;

/**
 * Class BlocksTest
 */
final class BlocksTest extends TestCase {

	/**
	 * The example block's registered name.
	 */
	private const BLOCK_NAME = 'amc-automata-features-features/example-block-dynamic';

	/**
	 * Drop the block type if a test registered it.
	 */
	public function tear_down(): void {
		if ( WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK_NAME ) ) {
			unregister_block_type( self::BLOCK_NAME );
		}

		parent::tear_down();
	}

	/**
	 * get_name() returns the exact block name.
	 */
	public function test_get_name_returns_the_block_name(): void {
		$this->assertSame( self::BLOCK_NAME, ExampleDynamicBlock::get_name() );
	}

	/**
	 * The block extends the framework AbstractBlock.
	 */
	public function test_extends_framework_abstract_block(): void {
		$this->assertInstanceOf(
			'rtCamp\WPPrimitives\Contracts\Abstracts\AbstractBlock',
			new ExampleDynamicBlock()
		);
	}

	/**
	 * The Blocks module manages the ExampleDynamicBlock class.
	 */
	public function test_module_lists_the_example_block(): void {
		$method = new ReflectionMethod( Blocks::class, 'get_classes' );
		$method->setAccessible( true );

		$classes = $method->invoke( new Blocks() );

		$this->assertContains( ExampleDynamicBlock::class, $classes );
	}

	/**
	 * The Blocks module is registered in Main (loaded as a Registrable module).
	 */
	public function test_module_registered_in_main(): void {
		$this->assertContains( Blocks::class, Main::CLASSES );
	}

	/**
	 * register_hooks() schedules block registration on init.
	 */
	public function test_register_hooks_registers_block_on_init(): void {
		$block = new ExampleDynamicBlock();
		$block->register_hooks();

		$this->assertNotFalse( has_action( 'init', [ $block, 'register_block' ] ) );
	}

	/**
	 * render() returns a string built from the block template.
	 */
	public function test_render_returns_a_string(): void {
		$block  = new ExampleDynamicBlock();
		$output = $block->render(
			[ 'example' => 'value' ],
			'',
			new \WP_Block( [ 'blockName' => self::BLOCK_NAME ] )
		);

		$this->assertIsString( $output );
	}

	/**
	 * render() is the public callback WordPress wires as render_callback.
	 */
	public function test_render_is_callable(): void {
		$this->assertIsCallable( [ new ExampleDynamicBlock(), 'render' ] );
	}
}
