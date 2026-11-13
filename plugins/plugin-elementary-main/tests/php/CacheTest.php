<?php
/**
 * Tests for the plugin ExampleCache (framework Cache utility usage example).
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Tests;

use AMC_Automata_Features\Features\Modules\Cache;
use AMC_Automata_Features\Features\Modules\Cache\ExampleCache;
use rtCamp\WPPrimitives\Contracts\Interfaces\Registrable;

/**
 * Class CacheTest
 *
 * Runs against real WordPress (wp-env): get_recent_post_ids() uses the framework
 * Cache utility, which goes straight through to WordPress's object cache. Since
 * the example caches into the 'amc_automata_features_features:queries' namespaced group,
 * each test flushes that group in tear_down() so nothing leaks between tests.
 */
final class CacheTest extends TestCase {

	/**
	 * Namespaced group the example caches into: prefix ':' group.
	 */
	private const RESOLVED_GROUP = 'amc_automata_features_features:queries';

	/**
	 * Cache key the example uses for the recent post IDs.
	 */
	private const KEY = 'recent_post_ids';

	/**
	 * Flush the example's namespaced group so cached entries never leak.
	 */
	public function tear_down(): void {
		if ( function_exists( 'wp_cache_flush_group' ) && function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( self::RESOLVED_GROUP );
		}
		wp_cache_delete( self::KEY, self::RESOLVED_GROUP );

		parent::tear_down();
	}

	/**
	 * The example implements the framework Registrable contract.
	 */
	public function test_implements_registrable(): void {
		$this->assertInstanceOf( Registrable::class, new ExampleCache() );
	}

	/**
	 * The example is listed in the Cache module's classes.
	 */
	public function test_listed_in_cache_module(): void {
		$module = new \ReflectionClass( Cache::class );
		$method = $module->getMethod( 'get_classes' );
		$method->setAccessible( true );
		$classes = $method->invoke( new Cache() );

		$this->assertContains( ExampleCache::class, $classes );
	}

	/**
	 * The first call computes and the result is written into the object cache
	 * under the example's namespaced group, so a direct wp_cache_get() finds it.
	 */
	public function test_first_call_computes_and_populates_the_cache(): void {
		$post_ids = self::factory()->post->create_many( 3, [ 'post_status' => 'publish' ] );

		$result = ( new ExampleCache() )->get_recent_post_ids();

		// The published posts we created are returned.
		foreach ( $post_ids as $id ) {
			$this->assertContains( $id, $result );
		}

		// The computed value is now sitting in the object cache under the group.
		$found  = false;
		$cached = wp_cache_get( self::KEY, self::RESOLVED_GROUP, false, $found );
		$this->assertTrue( $found, 'Expected the computed value to be cached after the first call.' );
		$this->assertSame( $result, $cached );
	}

	/**
	 * A call returns the cached value WITHOUT recomputing. Proven by seeding the
	 * example's cache slot with a sentinel: remember() short-circuits on the hit
	 * and returns the sentinel rather than querying the database. (Creating posts
	 * to prove this cannot work here: the booted plugin wires flush() onto
	 * save_post, so any new post would invalidate the very group under test.)
	 */
	public function test_returns_cached_value_without_recomputing(): void {
		self::factory()->post->create_many( 3, [ 'post_status' => 'publish' ] );

		// Seed the example's exact cache slot with a sentinel value.
		wp_cache_set( self::KEY, [ 4242 ], self::RESOLVED_GROUP );

		$result = ( new ExampleCache() )->get_recent_post_ids();

		$this->assertSame( [ 4242 ], $result, 'remember() should return the cached value, not recompute it.' );
	}

	/**
	 * flush() drops the example's group, so the next call recomputes and now
	 * reflects content added after the original cache write. This is the same
	 * group the save_post / deleted_post invalidation hooks flush.
	 */
	public function test_flush_invalidates_the_group_and_forces_recompute(): void {
		self::factory()->post->create_many( 3, [ 'post_status' => 'publish' ] );

		$example = new ExampleCache();
		$example->get_recent_post_ids();

		$new_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$example->flush();

		// flush_group support depends on the object-cache backend; only assert
		// the recompute when the group could actually be flushed.
		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			$after = $example->get_recent_post_ids();
			$this->assertContains( $new_id, $after, 'After flush the value should be recomputed and include new content.' );
		} else {
			$this->markTestSkipped( 'Object cache backend does not support flush_group.' );
		}
	}

	/**
	 * register_hooks() wires invalidation onto save_post and deleted_post so the
	 * cached query can never go stale on content change.
	 */
	public function test_register_hooks_wires_invalidation_on_content_change(): void {
		$example = new ExampleCache();
		$example->register_hooks();

		$this->assertNotFalse( has_action( 'save_post', [ $example, 'flush' ] ) );
		$this->assertNotFalse( has_action( 'deleted_post', [ $example, 'flush' ] ) );

		// Clean up the hooks we just added.
		remove_action( 'save_post', [ $example, 'flush' ] );
		remove_action( 'deleted_post', [ $example, 'flush' ] );
	}
}
