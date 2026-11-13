<?php
/**
 * Check: detect.cdn.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks\Detection;

use AMC\Checks\Check;
use AMC\Checks\Context;
use AMC\Checks\Group;
use AMC\Checks\Result;
use AMC\Checks\Schedule;
use AMC\Support\HeaderDetect;

/**
 * Class CdnDetect
 *
 * Detects the CDN in front of the site from one wp_remote_get() of the home
 * page (timeout 10 s), then HeaderDetect.
 *
 * Result::$data shape:
 *   url       string Home URL fetched.
 *   provider  string|null CDN provider slug.
 *   evidence  list<string> Evidence headers.
 *   page_cache_hit bool|null Whether the response came from a page cache.
 *   page_cache_evidence list<string>
 *   status_code int|null HTTP response code.
 *
 * Status: PASS.
 *
 * @since 0.1.0
 */
final class CdnDetect implements Check {

	/**
	 * Returns the home page response.
	 *
	 * @var callable(): array<string, mixed>|\WP_Error
	 */
	private $fetch_source;

	/**
	 * Returns the home URL.
	 *
	 * @var callable(): string
	 */
	private $url_source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $fetch_source Test seam; default wp_remote_get( home_url( '/' ), [ 'timeout' => 10 ] ).
	 * @param callable|null $url_source   Test seam; default home_url( '/' ).
	 */
	public function __construct( ?callable $fetch_source = null, ?callable $url_source = null ) {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get, WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
		$this->fetch_source = $fetch_source ?? static fn (): mixed => wp_remote_get( home_url( '/' ), [ 'timeout' => 10 ] );
		$this->url_source   = $url_source ?? static fn (): string => home_url( '/' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'detect.cdn';
	}

	/**
	 * {@inheritDoc}
	 */
	public function group(): Group {
		return Group::DETECTION;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'CDN detection', 'rtcamp-amc-copilot' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function schedule(): Schedule {
		return Schedule::WEEKLY;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Context $ctx Run context (unused here).
	 */
	public function run( Context $ctx ): Result { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by the Check contract.
		$url      = (string) ( $this->url_source )();
		$response = ( $this->fetch_source )();

		if ( is_wp_error( $response ) ) {
			return Result::info(
				sprintf( /* translators: %s: Error message. */ __( 'Could not fetch the home page: %s', 'rtcamp-amc-copilot' ), $response->get_error_message() ),
				[
					'url'                 => $url,
					'provider'            => null,
					'evidence'            => [],
					'page_cache_hit'      => null,
					'page_cache_evidence' => [],
					'status_code'         => null,
				]
			);
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$headers = wp_remote_retrieve_headers( $response );
		$map     = [];

		if ( is_array( $headers ) || $headers instanceof \Traversable ) {
			foreach ( $headers as $name => $value ) {
				$map[ strtolower( (string) $name ) ] = $value;
			}
		}

		$cdn   = HeaderDetect::cdn( $map );
		$cache = HeaderDetect::page_cache( $map );

		return Result::pass(
			$cdn['provider'] ? sprintf( /* translators: %s: CDN provider. */ __( 'CDN detected: %s.', 'rtcamp-amc-copilot' ), $cdn['provider'] ) : __( 'No CDN detected.', 'rtcamp-amc-copilot' ),
			[
				'url'                 => $url,
				'provider'            => $cdn['provider'],
				'evidence'            => $cdn['evidence'],
				'page_cache_hit'      => $cache['hit'],
				'page_cache_evidence' => $cache['evidence'],
				'status_code'         => $code,
			]
		);
	}
}
