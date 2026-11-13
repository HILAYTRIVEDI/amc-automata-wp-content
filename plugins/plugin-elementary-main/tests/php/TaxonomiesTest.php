<?php
/**
 * Tests for the Taxonomies module and its example taxonomies.
 *
 * Runs under real WordPress: register() actually registers the taxonomies,
 * so assertions go through taxonomy_exists() and get_taxonomy().
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Tests;

use AMC_Automata_Features\Features\Modules\Taxonomies;
use AMC_Automata_Features\Features\Modules\Taxonomies\ExampleTaxonomy;
use AMC_Automata_Features\Features\Modules\Taxonomies\ExampleTaxonomyTwo;
use ReflectionMethod;

/**
 * Class TaxonomiesTest
 */
final class TaxonomiesTest extends TestCase {

	/**
	 * Read the module's protected get_classes() list.
	 *
	 * @return array<int, class-string>
	 */
	private function module_classes(): array {
		$method = new ReflectionMethod( Taxonomies::class, 'get_classes' );
		$method->setAccessible( true );

		return $method->invoke( new Taxonomies() );
	}

	/**
	 * Both example taxonomies are listed in the module's class list.
	 */
	public function test_examples_are_listed_in_get_classes(): void {
		$classes = $this->module_classes();

		$this->assertContains( ExampleTaxonomy::class, $classes );
		$this->assertContains( ExampleTaxonomyTwo::class, $classes );
	}

	/**
	 * Each example is the expected concrete AbstractTaxonomy subtype.
	 */
	public function test_examples_are_taxonomy_instances(): void {
		$this->assertInstanceOf( 'rtCamp\WPPrimitives\Contracts\Abstracts\AbstractTaxonomy', new ExampleTaxonomy() );
		$this->assertInstanceOf( 'rtCamp\WPPrimitives\Contracts\Abstracts\AbstractTaxonomy', new ExampleTaxonomyTwo() );
	}

	/**
	 * The static get_slug() and get_object_types() are reachable without an
	 * instance and return the documented values.
	 */
	public function test_static_accessors_are_accessible(): void {
		$this->assertSame( 'taxonomy-slug', ExampleTaxonomy::get_slug() );
		$this->assertSame( [ 'post-type-slug' ], ExampleTaxonomy::get_object_types() );

		$this->assertSame( 'taxonomy-slug-two', ExampleTaxonomyTwo::get_slug() );
		$this->assertSame( [ 'post-type-slug-two' ], ExampleTaxonomyTwo::get_object_types() );
	}

	/**
	 * register() actually registers the first example taxonomy with the
	 * object types, labels and hierarchical setting it declares.
	 */
	public function test_example_taxonomy_registers(): void {
		( new ExampleTaxonomy() )->register();

		$this->assertTrue( taxonomy_exists( 'taxonomy-slug' ) );

		$object = get_taxonomy( 'taxonomy-slug' );
		$this->assertNotFalse( $object );
		$this->assertTrue( $object->public );
		$this->assertTrue( $object->show_in_rest );
		$this->assertTrue( $object->hierarchical );
		$this->assertContains( 'post-type-slug', $object->object_type );
		$this->assertSame( 'Taxonomy Label', $object->labels->singular_name );
		$this->assertSame( 'Taxonomy Labels', $object->labels->name );
	}

	/**
	 * register() actually registers the second example taxonomy, which keeps
	 * the non-hierarchical default.
	 */
	public function test_example_taxonomy_two_registers(): void {
		( new ExampleTaxonomyTwo() )->register();

		$this->assertTrue( taxonomy_exists( 'taxonomy-slug-two' ) );

		$object = get_taxonomy( 'taxonomy-slug-two' );
		$this->assertNotFalse( $object );
		$this->assertFalse( $object->hierarchical );
		$this->assertContains( 'post-type-slug-two', $object->object_type );
		$this->assertSame( 'Taxonomy Two Label', $object->labels->singular_name );
		$this->assertSame( 'Taxonomy Two Labels', $object->labels->name );
	}

	/**
	 * register_hooks() on the module wires registration onto the init hook.
	 */
	public function test_module_register_hooks_wires_init(): void {
		( new Taxonomies() )->register_hooks();

		$this->assertNotFalse( has_action( 'init' ) );
	}

	/**
	 * Drop any taxonomies this test registered so they do not leak into other
	 * tests.
	 */
	public function tear_down(): void {
		foreach ( [ 'taxonomy-slug', 'taxonomy-slug-two' ] as $slug ) {
			if ( taxonomy_exists( $slug ) ) {
				unregister_taxonomy( $slug );
			}
		}

		parent::tear_down();
	}
}
