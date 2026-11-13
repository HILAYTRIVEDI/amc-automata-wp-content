<?php
/**
 * Detection helpers for analytics tags and accessibility widgets in page HTML.
 *
 * Pure string analysis: no database, no HTTP, no global state. Every method
 * takes the raw home page HTML and returns plain arrays, so small "check"
 * classes can call them directly.
 *
 * @package AMC\Support
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- The AMC prefix belongs to AMC Copilot, whose rename is carried on another branch.

namespace AMC\Support;

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals

/**
 * Class - HtmlDetect
 */
final class HtmlDetect {

	/**
	 * The minimum number of characters after the "GTM-" container prefix.
	 *
	 * @var int
	 */
	private const GTM_MIN_LENGTH = 4;

	/**
	 * The minimum number of characters after the "G-" GA4 measurement prefix.
	 *
	 * @var int
	 */
	private const GA4_MIN_LENGTH = 6;

	/**
	 * Snippet markers that must appear near an ID for it to count. A plain
	 * sentence that happens to contain "GTM-…" or "G-…" must not match.
	 *
	 * @var string
	 */
	private const SNIPPET_HINTS = '/googletagmanager|google-analytics|gtag\(|dataLayer|gtm\.(?:js|start)|_setAccount|analytics\.js/i';

	/**
	 * Accessibility tool recognisers, in canonical output order.
	 *
	 * @var array<string, string>
	 */
	private const TOOL_PATTERNS = [
		'userway'     => '/userway/i',
		'accessibe'   => '/accessibe|acsbapp|acsb\.js/i',
		'audioeye'    => '/audioeye/i',
		'equalweb'    => '/equalweb/i',
		'recite-me'   => '/reciteme|recite\.me/i',
		'monsido'     => '/monsido/i',
		'siteimprove' => '/siteimprove/i',
	];

	/**
	 * Disallow instantiation - this class only exposes static helpers.
	 */
	private function __construct() {}

	/**
	 * Finds Google Tag Manager, GA4 and Universal Analytics IDs in gtag/gtm
	 * snippets, deduped and in order of first appearance.
	 *
	 * An ID only counts when a marketing snippet marker appears within ±400
	 * characters of it, so look-alike strings in editorial content are ignored.
	 *
	 * @param string $html Raw home page HTML.
	 *
	 * @return array{gtm: string[], ga4: string[], ua: string[]}
	 */
	public static function analytics( string $html ): array {
		$result = [
			'gtm' => [],
			'ga4' => [],
			'ua'  => [],
		];

		if ( '' === trim( $html ) ) {
			return $result;
		}

		$patterns = [
			'gtm' => '/GTM-[A-Z0-9]{' . self::GTM_MIN_LENGTH . ',}/i',
			'ga4' => '/(?<![A-Z0-9])G-[A-Z0-9]{' . self::GA4_MIN_LENGTH . ',}(?![A-Z0-9])/i',
			'ua'  => '/(?<![A-Z0-9])UA-\d{4,10}-\d{1,5}(?![0-9])/i',
		];

		foreach ( $patterns as $key => $pattern ) {
			if ( ! preg_match_all( $pattern, $html, $matches, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			foreach ( $matches[0] as $match ) {
				$id = strtoupper( (string) $match[0] );

				if ( in_array( $id, $result[ $key ], true ) ) {
					continue;
				}

				if ( ! self::near_snippet( $html, (int) $match[1] ) ) {
					continue;
				}

				$result[ $key ][] = $id;
			}
		}

		return $result;
	}

	/**
	 * Recognises accessibility overlay tools by their script URLs or globals.
	 *
	 * @param string $html Raw home page HTML.
	 *
	 * @return string[] Slugs of the recognised tools, in canonical order.
	 */
	public static function accessibility_tools( string $html ): array {
		$found = [];

		foreach ( self::TOOL_PATTERNS as $slug => $pattern ) {
			if ( preg_match( $pattern, $html ) ) {
				$found[] = $slug;
			}
		}

		return $found;
	}

	/**
	 * Whether a snippet marker appears within the window around an offset.
	 *
	 * @param string $html   Raw HTML.
	 * @param int    $offset Byte offset of the candidate ID.
	 *
	 * @return bool True when a snippet marker is near the ID.
	 */
	private static function near_snippet( string $html, int $offset ): bool {
		$window = 400;
		$start  = max( 0, $offset - $window );
		$nearby = substr( $html, $start, $window * 2 );

		return 1 === preg_match( self::SNIPPET_HINTS, $nearby );
	}
}
