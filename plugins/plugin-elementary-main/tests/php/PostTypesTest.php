<?php
/**
 * Tests for the PostTypes module and its example post types.
 *
 * Runs under real WordPress: register() actually registers the post types,
 * so assertions go through post_type_exists() and get_post_type_object().
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Tests;

use AMC_Automata_Features\Features\Modules\PostTypes;
use AMC_Automata_Features\Features\Modules\PostTypes\ExamplePostType;
use AMC_Automata_Features\Features\Modules\PostTypes\ExamplePostTypeTwo;
use ReflectionMethod;

/**
 * Class PostTypesTest
 */
final class PostTypesTest extends TestCase {

	/**
	 * Read the module's protected get_classes() list.
	 *
	 * @return array<int, class-string>
	 */
	private function module_classes(): array {
		$method = new ReflectionMethod( PostTypes::class, 'get_classes' );
		$method->setAccessible( true );

		return $method->invoke( new PostTypes() );
	}

	/**
	 * Both example post types are listed in the module's class list.
	 */
	public function test_examples_are_listed_in_get_classes(): void {
		$classes = $this->module_classes();

		$this->assertContains( ExamplePostType::class, $classes );
		$this->assertContains( ExamplePostTypeTwo::class, $classes );
	}

	/**
	 * Each example is the expected concrete AbstractPostType subtype.
	 */
	public function test_examples_are_post_type_instances(): void {
		$this->assertInstanceOf( 'rtCamp\WPPrimitives\Contracts\Abstracts\AbstractPostType', new ExamplePostType() );
		$this->assertInstanceOf( 'rtCamp\WPPrimitives\Contracts\Abstracts\AbstractPostType', new ExamplePostTypeTwo() );
	}

	/**
	 * The static get_slug() is reachable without an instance and returns the
	 * documented slugs.
	 */
	public function test_static_get_slug_is_accessible(): void {
		$this->assertSame( 'post-type-slug', ExamplePostType::get_slug() );
		$this->assertSame( 'post-type-slug-two', ExamplePostTypeTwo::get_slug() );
	}

	/**
	 * register() actually registers the first example post type with the
	 * labels, supports and hierarchical settings it declares.
	 */
	public function test_example_post_type_registers(): void {
		( new ExamplePostType() )->register();

		$this->assertTrue( post_type_exists( 'post-type-slug' ) );

		$object = get_post_type_object( 'post-type-slug' );
		$this->assertNotNull( $object );
		$this->assertTrue( $object->public );
		$this->assertTrue( $object->show_in_rest );
		$this->assertFalse( $object->hierarchical );
		$this->assertSame( 'dashicons-id', $object->menu_icon );
		$this->assertSame( 'Post Type Label', $object->labels->singular_name );
		$this->assertSame( 'Post Type Labels', $object->labels->name );
		$this->assertTrue( post_type_supports( 'post-type-slug', 'title' ) );
		$this->assertTrue( post_type_supports( 'post-type-slug', 'editor' ) );
		$this->assertTrue( post_type_supports( 'post-type-slug', 'custom-fields' ) );
	}

	/**
	 * The first example associates its supported taxonomy with the post type.
	 */
	public function test_example_post_type_associates_supported_taxonomy(): void {
		register_taxonomy( 'taxonomy-slug', [], [ 'public' => true ] );

		( new ExamplePostType() )->register();

		$this->assertContains( 'taxonomy-slug', get_object_taxonomies( 'post-type-slug' ) );
	}

	/**
	 * register() actually registers the second example post type.
	 */
	public function test_example_post_type_two_registers(): void {
		( new ExamplePostTypeTwo() )->register();

		$this->assertTrue( post_type_exists( 'post-type-slug-two' ) );

		$object = get_post_type_object( 'post-type-slug-two' );
		$this->assertNotNull( $object );
		$this->assertTrue( $object->public );
		$this->assertFalse( $object->hierarchical );
		$this->assertSame( 'dashicons-id-alt', $object->menu_icon );
		$this->assertSame( 'Post Type Two Label', $object->labels->singular_name );
		$this->assertSame( 'Post Type Two Labels', $object->labels->name );
	}

	/**
	 * register_hooks() on the module wires registration onto the init hook.
	 */
	public function test_module_register_hooks_wires_init(): void {
		( new PostTypes() )->register_hooks();

		$this->assertNotFalse( has_action( 'init' ) );
	}

	/**
	 * Drop any post types and taxonomies this test registered so they do not
	 * leak into other tests.
	 */
	public function tear_down(): void {
		foreach ( [ 'post-type-slug', 'post-type-slug-two' ] as $slug ) {
			if ( post_type_exists( $slug ) ) {
				unregister_post_type( $slug );
			}
		}

		if ( taxonomy_exists( 'taxonomy-slug' ) ) {
			unregister_taxonomy( 'taxonomy-slug' );
		}

		parent::tear_down();
	}
}
