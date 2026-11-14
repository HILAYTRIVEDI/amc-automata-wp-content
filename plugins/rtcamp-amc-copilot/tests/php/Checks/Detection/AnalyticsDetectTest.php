<?php
/**
 * Tests for the detect.analytics check.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Checks\Detection;

use AMC\Checks\Context;
use AMC\Checks\Detection\AnalyticsDetect;
use AMC\Checks\Group;
use AMC\Checks\Schedule;
use AMC\Checks\Status;
use AMC\Tests\TestCase;

/**
 * Class AnalyticsDetectTest
 *
 * The home page is served through the pre_http_request filter, so the check
 * runs with its real default seams and no network call is made.
 */
final class AnalyticsDetectTest extends TestCase {

	/**
	 * A home page carrying one GTM, one GA4, one UA snippet and a UserWay script.
	 *
	 * @var string
	 */
	private const HTML = <<<'HTML'
<!DOCTYPE html><html><head>
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','GTM-5RVKQX');</script>
<script async src="https://www.googletagmanager.com/gtag/js?id=G-AB12CD34EF"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','G-AB12CD34EF');</script>
<script type="text/javascript">var _gaq=_gaq||[];_gaq.push(['_setAccount','UA-27118030-1']);</script>
<script src="https://cdn.userway.org/widget.js"></script>
</head><body></body></html>
HTML;

	/**
	 * Serve a canned HTTP response for the default wp_remote_get() seam.
	 *
	 * @param array<string, mixed>|\WP_Error $response Response or error.
	 */
	private function serve( array|\WP_Error $response ): void {
		add_filter( 'pre_http_request', static fn (): array|\WP_Error => $response );
	}

	public function test_metadata(): void {
		$check = new AnalyticsDetect();

		$this->assertSame( 'detect.analytics', $check->id() );
		$this->assertSame( Group::DETECTION, $check->group() );
		$this->assertSame( Schedule::WEEKLY, $check->schedule() );
		$this->assertNotSame( '', $check->label() );
	}

	public function test_it_reports_the_ids_found_on_the_home_page(): void {
		$this->serve(
			[
				'response' => [ 'code' => 200 ],
				'body'     => self::HTML,
			]
		);

		$result = ( new AnalyticsDetect() )->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( [], $result->findings );
		$this->assertSame( home_url( '/' ), $result->data['url'] );
		$this->assertSame( [ 'GTM-5RVKQX' ], $result->data['gtm'] );
		$this->assertSame( [ 'G-AB12CD34EF' ], $result->data['ga4'] );
		$this->assertSame( [ 'UA-27118030-1' ], $result->data['ua'] );
		$this->assertSame( [ 'userway' ], $result->data['accessibility_tools'] );
		$this->assertSame( 200, $result->data['status_code'] );
	}

	public function test_a_blocked_home_page_is_info_not_a_failure(): void {
		$this->serve( new \WP_Error( 'http_request_failed', 'Loopback refused.' ) );

		$result = ( new AnalyticsDetect() )->run( Context::for_tests() );

		$this->assertSame( Status::INFO, $result->status );
		$this->assertSame( [], $result->findings );
		$this->assertSame( [], $result->data['gtm'] );
		$this->assertSame( [], $result->data['ga4'] );
		$this->assertSame( [], $result->data['ua'] );
		$this->assertSame( [], $result->data['accessibility_tools'] );
		$this->assertNull( $result->data['status_code'] );
		$this->assertStringContainsString( 'Could not fetch the home page', $result->summary );
	}

	public function test_result_is_json_serialisable(): void {
		$this->serve(
			[
				'response' => [ 'code' => 200 ],
				'body'     => self::HTML,
			]
		);

		$result = ( new AnalyticsDetect() )->run( Context::for_tests() );

		$this->assertIsString( wp_json_encode( $result->to_array() ) );
	}
}
