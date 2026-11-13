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
use AMC\Support\CoreSiteHealth;
use AMC\Support\HeaderDetect;

/**
 * Class CacheCheck
 *
 * Reports persistent object cache and page cache status from core's Site
 * Health tests (`get_test_persistent_object_cache()` and
 * `get_test_page_cache()`), called whatever the environment type is (core only
 * lists them in production). Core keeps the page cache detail (response
 * headers, `advanced-cache.php`, response time) in the private
 * `get_page_cache_detail()`, which is read through reflection; when that is
 * unavailable the home page is fetched and its headers inspected instead.
 *
 * Cost: core makes three loopback requests per page cache call, and the
 * detail is read by a second call, so up to six requests (each capped at
 * HTTP_TIMEOUT seconds).
 *
 * Result::$data shape:
 *   persistent_object_cache bool     wp_using_ext_object_cache()
 *   page_cache_hit          bool|null Page cache headers or advanced-cache.php present; null when undetermined.
 *   page_cache_evidence     list<string>
 *   page_cache_status       string|null good|recommended|critical (Site Health); null when the test could not run.
 *   page_cache_label        string|null Site Health's label for the page cache test.
 *   object_cache_status     string|null good|recommended|critical (Site Health).
 *   object_cache_label      string|null Site Health's label for the object cache test.
 *   response_time_ms        int|null    Median server response time measured by Site Health.
 *   advanced_cache_present  bool|null   A page cache plugin drop-in is active.
 *
 * Status: PASS when either cache is present, INFO otherwise.
 *
 * @since 0.1.0
 */
final class CacheCheck implements Check {

	/**
	 * Cap, in seconds, on each request core's page cache test makes.
	 */
	private const HTTP_TIMEOUT = 5;

	/**
	 * Returns whether a persistent object cache is in use.
	 *
	 * @var callable(): bool
	 */
	private $object_cache_source;

	/**
	 * Returns the home page response (the fallback when Site Health's detail is unavailable).
	 *
	 * @var callable(): mixed
	 */
	private $fetch_source;

	/**
	 * Returns the core Site Health results.
	 *
	 * @var callable(): ?array{page_cache: array<string, mixed>, object_cache: array<string, mixed>, detail: mixed}
	 */
	private $health_source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $object_cache_source Test seam; default wp_using_ext_object_cache().
	 * @param callable|null $fetch_source        Test seam; default wp_remote_get( home_url( '/' ), [ 'timeout' => 10 ] ).
	 * @param callable|null $health_source       Test seam; default runs the core Site Health tests. Returns null when they are unavailable.
	 */
	public function __construct( ?callable $object_cache_source = null, ?callable $fetch_source = null, ?callable $health_source = null ) {
		$this->object_cache_source = $object_cache_source ?? static fn (): bool => function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get, WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
		$this->fetch_source  = $fetch_source ?? static fn (): mixed => wp_remote_get( home_url( '/' ), [ 'timeout' => 10 ] );
		$this->health_source = $health_source ?? static fn (): ?array => self::core_health();
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
		$health       = ( $this->health_source )();
		$raw_detail   = is_array( $health ) ? ( $health['detail'] ?? null ) : null;
		$detail       = is_array( $raw_detail ) ? $raw_detail : null;
		$page_test    = is_array( $health ) && is_array( $health['page_cache'] ?? null ) ? $health['page_cache'] : null;
		$object_test  = is_array( $health ) && is_array( $health['object_cache'] ?? null ) ? $health['object_cache'] : null;
		$page_hit     = null;
		$evidence     = [];

		if ( null !== $detail ) {
			$headers  = array_values( array_map( 'strval', (array) ( $detail['headers'] ?? [] ) ) );
			$advanced = (bool) ( $detail['advanced_cache_present'] ?? false );
			$page_hit = [] !== $headers || $advanced;

			foreach ( $headers as $header ) {
				$evidence[] = 'response header: ' . $header;
			}

			if ( $advanced ) {
				$evidence[] = 'advanced-cache.php drop-in present';
			}
		} elseif ( null === $raw_detail ) {
			// Site Health (or its detail method) is unavailable: inspect the home page ourselves.
			[ $page_hit, $evidence ] = $this->page_cache_from_home_page();
		}
		// Otherwise core's detail is a WP_Error (loopback problem): leave the hit undetermined.

		$response_time = null !== $detail && isset( $detail['response_time'] ) && is_numeric( $detail['response_time'] ) ? (int) round( (float) $detail['response_time'] ) : null;

		$data = [
			'persistent_object_cache' => $object_cache,
			'page_cache_hit'          => $page_hit,
			'page_cache_evidence'     => $evidence,
			'page_cache_status'       => null !== $page_test ? (string) ( $page_test['status'] ?? '' ) : null,
			'page_cache_label'        => null !== $page_test ? wp_strip_all_tags( (string) ( $page_test['label'] ?? '' ) ) : null,
			'object_cache_status'     => null !== $object_test ? (string) ( $object_test['status'] ?? '' ) : null,
			'object_cache_label'      => null !== $object_test ? wp_strip_all_tags( (string) ( $object_test['label'] ?? '' ) ) : null,
			'response_time_ms'        => $response_time,
			'advanced_cache_present'  => null !== $detail ? (bool) ( $detail['advanced_cache_present'] ?? false ) : null,
		];

		if ( $object_cache || true === $page_hit ) {
			return Result::pass( __( 'A cache is in use.', 'rtcamp-amc-copilot' ), $data );
		}

		return Result::info( __( 'No persistent object cache or page cache detected.', 'rtcamp-amc-copilot' ), $data );
	}

	/**
	 * Runs core's two cache tests and reads the page cache detail.
	 *
	 * @return array{page_cache: array<string, mixed>, object_cache: array<string, mixed>, detail: mixed}|null Null when Site Health is unavailable.
	 */
	private static function core_health(): ?array {
		$health = CoreSiteHealth::instance();
		if ( null === $health ) {
			return null;
		}

		return CoreSiteHealth::with_http_timeout(
			static function () use ( $health ): array {
				return [
					'page_cache'   => $health->get_test_page_cache(),
					'object_cache' => $health->get_test_persistent_object_cache(),
					'detail'       => self::page_cache_detail( $health ),
				];
			},
			self::HTTP_TIMEOUT
		);
	}

	/**
	 * Reads core's private get_page_cache_detail().
	 *
	 * @param \WP_Site_Health $health Core instance.
	 *
	 * @return array<string, mixed>|\WP_Error|null Null when the method no longer exists or cannot be read.
	 */
	private static function page_cache_detail( \WP_Site_Health $health ) {
		if ( ! method_exists( $health, 'get_page_cache_detail' ) ) {
			return null;
		}

		try {
			$method = new \ReflectionMethod( $health, 'get_page_cache_detail' );

			return $method->invoke( $health );
		} catch ( \ReflectionException $e ) {
			return null;
		}
	}

	/**
	 * Fallback: detects the page cache from the home page response headers.
	 *
	 * @return array{0: bool|null, 1: list<string>}
	 */
	private function page_cache_from_home_page(): array {
		$response = ( $this->fetch_source )();

		if ( is_wp_error( $response ) ) {
			return [ null, [] ];
		}

		$headers = wp_remote_retrieve_headers( $response );
		$map     = [];

		if ( is_array( $headers ) || $headers instanceof \Traversable ) {
			foreach ( $headers as $name => $value ) {
				$map[ strtolower( (string) $name ) ] = $value;
			}
		}

		$cache = HeaderDetect::page_cache( $map );

		return [ $cache['hit'], $cache['evidence'] ];
	}
}
