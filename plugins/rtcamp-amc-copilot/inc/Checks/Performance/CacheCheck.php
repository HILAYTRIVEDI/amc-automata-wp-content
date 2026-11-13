<?php
/**
 * Check: perf.cache.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks\Performance;

use AMC\Checks\Check;
use AMC\Checks\Context;
use AMC\Checks\Group;
use AMC\Checks\Result;
use AMC\Checks\Schedule;

/**
 * Class CacheCheck
 *
 * Reports persistent object cache and page cache status.
 *
 * Result::$data shape:
 *   persistent_object_cache bool
 *   page_cache_hit         bool|null
 *   page_cache_evidence    list<string>
 *
 * Status: PASS when either cache is present, INFO otherwise.
 *
 * @since 0.1.0
 */
final class CacheCheck implements Check {

	/**
	 * Returns whether a persistent object cache is in use.
	 *
	 * @var callable(): bool
	 */
	private $object_cache_source;

	/**
	 * Returns the home page response.
	 *
	 * @var callable(): mixed
	 */
	private $fetch_source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $object_cache_source Test seam; default wp_using_ext_object_cache().
	 * @param callable|null $fetch_source        Test seam; default wp_remote_get( home_url( '/' ), [ 'timeout' => 10 ] ).
	 */
	public function __construct( ?callable $object_cache_source = null, ?callable $fetch_source = null ) {
		$this->object_cache_source = $object_cache_source ?? static fn (): bool => function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get, WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
		$this->fetch_source = $fetch_source ?? static fn (): mixed => wp_remote_get( home_url( '/' ), [ 'timeout' => 10 ] );
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'perf.cache';
	}

	/**
	 * {@inheritDoc}
	 */
	public function group(): Group {
		return Group::PERFORMANCE;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Caching', 'rtcamp-amc-copilot' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function schedule(): Schedule {
		return Schedule::DAILY;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Context $ctx Run context (unused here).
	 */
	public function run( Context $ctx ): Result { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by the Check contract.
		$object_cache = (bool) ( $this->object_cache_source )();
		$response     = ( $this->fetch_source )();
		$page_hit     = null;
		$evidence     = [];

		if ( ! is_wp_error( $response ) ) {
			$headers = wp_remote_retrieve_headers( $response );
			$map     = [];

			if ( is_array( $headers ) || $headers instanceof \Traversable ) {
				foreach ( $headers as $name => $value ) {
					$map[ strtolower( (string) $name ) ] = $value;
				}
			}

			$cache    = \AMC\Support\HeaderDetect::page_cache( $map );
			$page_hit = $cache['hit'];
			$evidence = $cache['evidence'];
		}

		$data = [
			'persistent_object_cache' => $object_cache,
			'page_cache_hit'          => $page_hit,
			'page_cache_evidence'     => $evidence,
		];

		if ( $object_cache || true === $page_hit ) {
			return Result::pass( __( 'A cache is in use.', 'rtcamp-amc-copilot' ), $data );
		}

		return Result::info( __( 'No persistent object cache or page cache detected.', 'rtcamp-amc-copilot' ), $data );
	}
}
