<?php
/**
 * Tests for the plugin ExampleTransients (framework Transients utility usage example).
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Tests;

use AMC_Automata_Features\Features\Modules\Transients;
use AMC_Automata_Features\Features\Modules\Transients\ExampleTransients;
use rtCamp\WPPrimitives\Contracts\Interfaces\Registrable;
use rtCamp\WPPrimitives\Utils\Transients as FrameworkTransients;

/**
 * Class TransientsTest
 *
 * Runs against real WordPress (wp-env): get/set hit the real transient API,
 * which (with no persistent object cache) is backed by the options table and
 * rolled back per test by WP_UnitTestCase's DB transaction. We also delete the
 * namespaced transient in tear_down() as belt-and-braces.
 *
 * The example's get_remote_payload() makes a real wp_remote_get() to example.com
 * on a cache miss, so the network-touching path is exercised only indirectly:
 * we pre-seed the prefix-namespaced transient and assert the example serves it
 * from cache (the read path), and verify namespacing/expiry through the same
 * framework Transients wrapper the example uses.
 */
final class TransientsTest extends TestCase {

	/**
	 * Prefix the example wraps its transients with.
	 */
	private const PREFIX = 'amc_automata_features_features';

	/**
	 * Logical key the example stores under (KEY const on ExampleTransients).
	 */
	private const KEY = 'remote_payload';

	/**
	 * Fully namespaced key WordPress actually stores under:
	 * strlen(prefix) ':' prefix '_' key. strlen('amc_automata_features_features') === 21.
	 */
	private const NAMESPACED_KEY = '21:amc_automata_features_features_remote_payload';

	/**
	 * Delete the namespaced transient so nothing leaks between tests.
	 */
	public function tear_down(): void {
		delete_transient( self::NAMESPACED_KEY );

		parent::tear_down();
	}

	/**
	 * The example implements the framework Registrable contract.
	 */
	public function test_implements_registrable(): void {
		$this->assertInstanceOf( Registrable::class, new ExampleTransients() );
	}

	/**
	 * The example is listed in the Transients module's classes.
	 */
	public function test_listed_in_transients_module(): void {
		$module = new \ReflectionClass( Transients::class );
		$method = $module->getMethod( 'get_classes' );
		$method->setAccessible( true );
		$classes = $method->invoke( new Transients() );

		$this->assertContains( ExampleTransients::class, $classes );
	}

	/**
	 * The example stores under a prefix-namespaced transient: a value written
	 * through the framework wrapper with the example's prefix is readable as the
	 * namespaced key but NOT as the bare logical key.
	 */
	public function test_value_is_stored_under_the_namespaced_key(): void {
		( new FrameworkTransients( self::PREFIX ) )->set( self::KEY, [ 'hello' => 'world' ] );

		// Bare, unprefixed key must not resolve; the namespaced one must.
		$this->assertFalse( get_transient( self::KEY ) );
		$this->assertSame( [ 'hello' => 'world' ], get_transient( self::NAMESPACED_KEY ) );
	}

	/**
	 * On a hit, get_remote_payload() returns the cached value from the
	 * prefix-namespaced transient without making a remote request. We pre-seed
	 * the transient under the exact namespaced key, then assert it is served.
	 */
	public function test_get_remote_payload_serves_cached_value_within_ttl(): void {
		$payload = [
			'id'    => 7,
			'items' => [ 'a', 'b' ],
		];

		// Seed the same namespaced key the example reads from.
		set_transient( self::NAMESPACED_KEY, $payload, HOUR_IN_SECONDS );

		$first  = ( new ExampleTransients() )->get_remote_payload();
		$second = ( new ExampleTransients() )->get_remote_payload();

		$this->assertSame( $payload, $first, 'Cached payload should be returned verbatim.' );
		$this->assertSame( $first, $second, 'A second call within TTL returns the same cached value.' );

		// The underlying transient is still in place.
		$this->assertSame( $payload, get_transient( self::NAMESPACED_KEY ) );
	}

	/**
	 * Deleting the namespaced transient removes the cached value, so the next
	 * read sees a miss (false) rather than the old payload.
	 */
	public function test_delete_clears_the_namespaced_transient(): void {
		$store = new FrameworkTransients( self::PREFIX );

		$store->set( self::KEY, [ 'cached' => true ], MINUTE_IN_SECONDS );
		$this->assertSame( [ 'cached' => true ], $store->get( self::KEY ) );

		$this->assertTrue( $store->delete( self::KEY ) );
		$this->assertFalse( $store->get( self::KEY ) );
		$this->assertFalse( get_transient( self::NAMESPACED_KEY ) );
	}

	/**
	 * register_hooks() is intentionally a no-op for this example (the read path
	 * is exposed via get_remote_payload()); it must run without error.
	 */
	public function test_register_hooks_is_a_safe_noop(): void {
		$this->expectNotToPerformAssertions();

		( new ExampleTransients() )->register_hooks();
	}
}
