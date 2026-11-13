<?php
/**
 * Tests for the HtmlDetect helpers.
 *
 * @package AMC\Support
 */

declare( strict_types = 1 );

namespace AMC\Support\Tests;

use AMC\Support\HtmlDetect;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 4 ) . '/inc/Support/HtmlDetect.php';

/**
 * Class HtmlDetectTest
 */
final class HtmlDetectTest extends TestCase {

	/**
	 * A realistic Google Tag Manager container snippet (one line, minified).
	 */
	private function gtm_snippet(): string {
		return <<<'HTML'
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','GTM-5RVKQX');</script>
HTML;
	}

	/**
	 * A realistic GA4 gtag snippet (script tag plus config call).
	 */
	private function ga4_snippet(): string {
		return <<<'HTML'
<script async src="https://www.googletagmanager.com/gtag/js?id=G-AB12CD34EF"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','G-AB12CD34EF');</script>
HTML;
	}

	/**
	 * A realistic legacy Universal Analytics async snippet.
	 */
	private function ua_snippet(): string {
		return <<<'HTML'
<script type="text/javascript">
  var _gaq = _gaq || [];
  _gaq.push(['_setAccount', 'UA-27118030-1']);
  _gaq.push(['_trackPageview']);
  (function() {
    var ga = document.createElement('script'); ga.type = 'text/javascript'; ga.async = true;
    ga.src = ('https:' == document.location.protocol ? 'https://ssl' : 'http://www') + '.google-analytics.com/ga.js';
    var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(ga, s);
  })();
</script>
HTML;
	}

	/**
	 * Finds one GTM id from a real container snippet.
	 */
	public function test_finds_a_gtm_container_id(): void {
		$result = HtmlDetect::analytics( $this->gtm_snippet() );

		$this->assertSame( [ 'GTM-5RVKQX' ], $result['gtm'] );
		$this->assertSame( [], $result['ga4'] );
		$this->assertSame( [], $result['ua'] );
	}

	/**
	 * The GTM id in the noscript iframe is deduped against the script snippet.
	 */
	public function test_dedupes_a_gtm_id_across_script_and_noscript(): void {
		$html  = $this->gtm_snippet();
		$html .= "\n<noscript><iframe src=\"https://www.googletagmanager.com/ns.html?id=GTM-5RVKQX\" height=\"0\" width=\"0\" style=\"display:none;visibility:hidden\"></iframe></noscript>";

		$this->assertSame( [ 'GTM-5RVKQX' ], HtmlDetect::analytics( $html )['gtm'] );
	}

	/**
	 * Finds a GA4 measurement id from a real gtag snippet.
	 */
	public function test_finds_a_ga4_measurement_id(): void {
		$result = HtmlDetect::analytics( $this->ga4_snippet() );

		$this->assertSame( [ 'G-AB12CD34EF' ], $result['ga4'] );
		$this->assertSame( [], $result['gtm'] );
	}

	/**
	 * Finds a legacy UA property id from the async snippet.
	 */
	public function test_finds_a_legacy_universal_analytics_id(): void {
		$result = HtmlDetect::analytics( $this->ua_snippet() );

		$this->assertSame( [ 'UA-27118030-1' ], $result['ua'] );
	}

	/**
	 * Multiple ids of different kinds are all returned, GTM first.
	 */
	public function test_finds_all_three_kinds_together(): void {
		$html = $this->gtm_snippet() . $this->ga4_snippet() . $this->ua_snippet();

		$result = HtmlDetect::analytics( $html );

		$this->assertSame( [ 'GTM-5RVKQX' ], $result['gtm'] );
		$this->assertSame( [ 'G-AB12CD34EF' ], $result['ga4'] );
		$this->assertSame( [ 'UA-27118030-1' ], $result['ua'] );
	}

	/**
	 * Two GA4 properties on one page both appear, deduped.
	 */
	public function test_finds_two_distinct_ga4_ids_without_duplicates(): void {
		$html  = $this->ga4_snippet();
		$html .= "\n<script>gtag('config', 'G-ZZ99XX88YY');</script>";
		$html .= "\n<script>gtag('config', 'G-AB12CD34EF');</script>";

		$this->assertSame( [ 'G-AB12CD34EF', 'G-ZZ99XX88YY' ], HtmlDetect::analytics( $html )['ga4'] );
	}

	/**
	 * Look-alike strings in editorial content far from any snippet are ignored.
	 */
	public function test_ignores_lookalike_ids_in_plain_text(): void {
		$html = <<<'HTML'
<article><h2>Skis and watches</h2><p>Our favourite G-SHOCK this year is the GA-2100-1AER, and the Rossignol skis
model G-2100-1 come second. The old campaign code GTM-1 is retired. See also SKU UA-TRACK and part
number G-4400 for the junior sizes.</p></article>
HTML;

		$result = HtmlDetect::analytics( $html );

		$this->assertSame( [], $result['gtm'] );
		$this->assertSame( [], $result['ga4'] );
		$this->assertSame( [], $result['ua'] );
	}

	/**
	 * A look-alike id that appears only in prose, hundreds of characters away
	 * from the nearest snippet, is not counted even on a page that does use GA4.
	 */
	public function test_ignores_a_lookalike_id_far_from_the_snippet(): void {
		$filler = str_repeat( '<p>Premium ski boots and goggles, now with free shipping on all orders.</p>', 20 );

		$html = $this->ga4_snippet() . $filler . '<p>Text mentions the product code G-99887YAA somewhere in prose.</p>';

		$this->assertSame( [ 'G-AB12CD34EF' ], HtmlDetect::analytics( $html )['ga4'] );
	}

	/**
	 * Empty and whitespace-only input returns empty buckets.
	 */
	public function test_empty_html_returns_empty_buckets(): void {
		$result = HtmlDetect::analytics( '   ' );

		$this->assertSame(
			[
				'gtm' => [],
				'ga4' => [],
				'ua'  => [],
			],
			$result
		);
	}

	/**
	 * Recognises the seven supported accessibility tools from real script tags.
	 *
	 * @dataProvider provide_accessibility_tools
	 *
	 * @param string $slug Expected tool slug.
	 * @param string $html Realistic snippet for that tool.
	 */
	public function test_recognises_accessibility_tools( string $slug, string $html ): void {
		$this->assertSame( [ $slug ], HtmlDetect::accessibility_tools( $html ) );
	}

	/**
	 * Realistic snippets for each supported accessibility tool.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function provide_accessibility_tools(): array {
		return [
			'userway'     => [ 'userway', '<script src="https://cdn.userway.org/widget.js" data-account="abc123"></script>' ],
			'accessibe'   => [ 'accessibe', '<script>(function(){var s=document.createElement("script");s.src="https://acsbapp.com/apps/app/dist/js/app.js";s.async=1;document.body.appendChild(s)})();</script>' ],
			'accessibe-2' => [ 'accessibe', '<script src="https://accessibe.com/js/acsb.js"></script>' ],
			'audioeye'    => [ 'audioeye', '<script>!function(){var t=window.AudioEye=window.AudioEye||{};}();</script><script src="https://wsmcdn.audioeye.com/aem.js"></script>' ],
			'equalweb'    => [ 'equalweb', '<script src="https://www.equalweb.com/widget/tool.js?lang=en"></script>' ],
			'recite-me'   => [ 'recite-me', '<script src="//app.reciteme.com/js/recite.js"></script>' ],
			'recite-me-2' => [ 'recite-me', '<script src="https://cdn.recite.me/js/recite.js"></script>' ],
			'monsido'     => [ 'monsido', '<script>window._monsido={"token":"abc"}</script><script src="https://app.monsido.com/domain_modules/monsido.js"></script>' ],
			'siteimprove' => [ 'siteimprove', '<script async type="text/javascript" src="https://cdn.siteimprove.net/js/siteimprove.js"></script>' ],
		];
	}

	/**
	 * Several tools on one page are all returned in canonical order.
	 */
	public function test_lists_multiple_tools_in_canonical_order(): void {
		$html  = '<script src="https://cdn.siteimprove.net/js/siteimprove.js"></script>';
		$html .= '<script src="https://cdn.userway.org/widget.js"></script>';
		$html .= '<script src="//app.reciteme.com/js/recite.js"></script>';

		$this->assertSame( [ 'userway', 'recite-me', 'siteimprove' ], HtmlDetect::accessibility_tools( $html ) );
	}

	/**
	 * Look-alike words that are not accessibility tools produce no matches.
	 *
	 * @dataProvider provide_accessibility_negatives
	 *
	 * @param string $html HTML that must not match any tool.
	 */
	public function test_does_not_flag_lookalike_strings( string $html ): void {
		$this->assertSame( [], HtmlDetect::accessibility_tools( $html ) );
	}

	/**
	 * Look-alike snippets that must not be recognised as accessibility tools.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function provide_accessibility_negatives(): array {
		return [
			'recite the alphabet'    => [ '<p>Please recite the alphabet and recite me a poem.</p>' ],
			'siteimprove hyphenated' => [ '<p>Visit site-improve.net for our website clinic.</p>' ],
			'acsb letters'           => [ '<p>The ABCSB consortium and acsb archives hold records.</p>' ],
			'monsido fragment'       => [ '<p>Listen to the audio eye opening scene of the film.</p>' ],
			'empty'                  => [ '' ],
		];
	}
}
