<?php
/**
 * Check: detect.accessibility.
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
 * Class AccessibilityDetect
 *
 * Detects accessibility overlay tools in the home page HTML and looks for a
 * page whose slug contains "accessibility" (statement page).
 *
 * Result::$data shape:
 *   url                  string Home URL fetched.
 *   tools                list<string> Recognised accessibility tool slugs.
 *   statement_pages      list<array{title, url}>
 *   status_code          int|null HTTP response code.
 *
 * Status: PASS.
 *
 * @since 0.1.0
 */
final class AccessibilityDetect implements Check {

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
	 * Returns pages whose slug contains "accessibility".
	 *
	 * @var callable(): list<array{title: string, url: string}>
	 */
	private $page_source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $fetch_source Test seam; default wp_remote_get( home_url( '/' ), [ 'timeout' => 10 ] ).
	 * @param callable|null $url_source   Test seam; default home_url( '/' ).
	 * @param callable|null $page_source  Test seam; default a DB query for matching pages.
	 */
	public function __construct( ?callable $fetch_source = null, ?callable $url_source = null, ?callable $page_source = null ) {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get, WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
		$this->fetch_source = $fetch_source ?? static fn (): mixed => wp_remote_get( home_url( '/' ), [ 'timeout' => 10 ] );
		$this->url_source   = $url_source ?? static fn (): string => home_url( '/' );
		$this->page_source  = $page_source ?? static function (): array {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$rows = $wpdb->get_results(
				"SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_name LIKE '%accessibility%' LIMIT 5",
				ARRAY_A
			);

			$out = [];
			foreach ( (array) $rows as $row ) {
				$out[] = [
					'title' => (string) ( $row['post_title'] ?? '' ),
					'url'   => (string) get_permalink( (int) ( $row['ID'] ?? 0 ) ),
				];
			}

			return $out;
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'detect.accessibility';
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
		return __( 'Accessibility detection', 'rtcamp-amc-copilot' );
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
					'url'             => $url,
					'tools'           => [],
					'statement_pages' => [],
					'status_code'     => null,
				]
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$html = (string) wp_remote_retrieve_body( $response );

		return Result::pass(
			__( 'Home page scanned for accessibility tools and statement pages.', 'rtcamp-amc-copilot' ),
			[
				'url'             => $url,
				'tools'           => HtmlDetect::accessibility_tools( $html ),
				'statement_pages' => (array) ( $this->page_source )(),
				'status_code'     => $code,
			]
		);
	}
}
