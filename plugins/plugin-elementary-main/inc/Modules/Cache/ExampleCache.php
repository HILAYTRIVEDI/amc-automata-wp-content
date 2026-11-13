<?php
/**
 * Example: cache an expensive query with the framework Cache utility.
 *
 * Demonstrates {@see \rtCamp\WPPrimitives\Utils\Cache}: a per-plugin namespaced
 * cache, `remember()` to compute-once-and-reuse, and group invalidation on
 * content changes. Swap the query for your own expensive work.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Modules\Cache;

use rtCamp\WPPrimitives\Contracts\Interfaces\Registrable;
use rtCamp\WPPrimitives\Utils\Cache;

/**
 * Class - ExampleCache
 */
final class ExampleCache implements Registrable {

	/**
	 * Cache group these entries live in (namespaced by the Cache instance).
	 */
	private const GROUP = 'queries';

	/**
	 * Framework cache, namespaced to this plugin so its groups can never
	 * collide with another consumer's.
	 *
	 * @var Cache
	 */
	private Cache $cache;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->cache = new Cache( 'amc_automata_features_features' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Invalidate the cached query whenever content changes, so it can never
	 * go stale.
	 */
	public function register_hooks(): void {
		add_action( 'save_post', [ $this, 'flush' ] );
		add_action( 'deleted_post', [ $this, 'flush' ] );
	}

	/**
	 * IDs of the most recent published posts. Computed at most once per cache
	 * window and reused on every later call until invalidated.
	 *
	 * @return int[] Post IDs.
	 */
	public function get_recent_post_ids(): array {
		return $this->cache->remember(
			'recent_post_ids',
			static function (): array {
				return get_posts(
					[
						'numberposts' => 5,
						'post_status' => 'publish',
						'fields'      => 'ids',
					]
				);
			},
			self::GROUP,
			5 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Drop every cached entry in this example's group.
	 */
	public function flush(): void {
		$this->cache->flush_group( self::GROUP );
	}
}
