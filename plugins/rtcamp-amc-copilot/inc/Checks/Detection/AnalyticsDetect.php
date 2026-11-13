<?php
/**
 * Check: detect.analytics.
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
use AMC\Support\HtmlDetect;

/**
 * Class AnalyticsDetect
 *
 * Detects GTM / GA4 / UA IDs and accessibility tools from one wp_remote_get()
 * of the home page (timeout 10 s).
 *
 * Result::$data shape:
 *   url           string Home URL fetched.
 *   gtm           list<string> GTM container IDs.
 *   ga4           list<string> GA4 measurement IDs.
 *   ua            list<string> Universal Analytics IDs.
 *   accessibility_tools list<string> Recognised accessibility tool slugs.
 *   status_code   int|null HTTP response code.
 *
 * Status: PASS.
 *
 * @since 0.1.0
 */
final class AnalyticsDetect implements Check {

	/**
	 * Returns the home page response.
	 *
	 * @var callable(): mixed
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
		return 'detect.analytics';
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
		return __( 'Analytics and tagging detection', 'rtcamp-amc-copilot' );
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
					'gtm'                 => [],
					'ga4'                 => [],
					'ua'                  => [],
					'accessibility_tools' => [],
					'status_code'         => null,
				]
			);
		}

		$code      = (int) wp_remote_retrieve_response_code( $response );
		$html      = (string) wp_remote_retrieve_body( $response );
		$analytics = HtmlDetect::analytics( $html );
		$tools     = HtmlDetect::accessibility_tools( $html );

		return Result::pass(
			__( 'Home page scanned for analytics and accessibility tools.', 'rtcamp-amc-copilot' ),
			[
				'url'                 => $url,
				'gtm'                 => $analytics['gtm'],
				'ga4'                 => $analytics['ga4'],
				'ua'                  => $analytics['ua'],
				'accessibility_tools' => $tools,
				'status_code'         => $code,
			]
		);
	}
}
