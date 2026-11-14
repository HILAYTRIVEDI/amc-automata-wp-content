<?php
/**
 * Tests for the detect.cdn check.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Checks\Detection;

use AMC\Checks\Context;
use AMC\Checks\Detection\CdnDetect;
use AMC\Checks\Group;
use AMC\Checks\Schedule;
use AMC\Checks\Status;
use AMC\Tests\TestCase;

/**
 * Class CdnDetectTest
 *
 * The home page is served through the pre_http_request filter, so the check
 * runs with its real default seams and no network call is made.
 */
final class CdnDetectTest extends TestCase {

	/**
	 * Serve a canned HTTP response for the default wp_remote_get() seam.
	 *
	 * @param array<string, mixed>|\WP_Error $response Response or error.
	 */
	private function serve( array|\WP_Error $response ): void {
		add_filter( 'pre_http_request', static fn (): array|\WP_Error => $response );
	}

	public function test_metadata(): void {
		$check = new CdnDetect();

		$this->assertSame( 'detect.cdn', $check->id() );
		$this->assertSame( Group::DETECTION, $check->group() );
		$this->assertSame( Schedule::WEEKLY, $check->schedule() );
		$this->assertNotSame( '', $check->label() );
	}

	public function test_it_reports_the_cdn_and_the_page_cache_state(): void {
		$this->serve(
			[
				'response' => [ 'code' => 200 ],
				'body'     => '<html><body></body></html>',
				'headers'  => [
					'cf-ray'          => '8ab1c2d3e4f5-LHR',
					'cf-cache-status' => 'HIT',
					'server'          => 'cloudflare',
				],
			]
		);

		$result = ( new CdnDetect() )->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( [], $result->findings );
		$this->assertSame( home_url( '/' ), $result->data['url'] );
		$this->assertSame( 'cloudflare', $result->data['provider'] );
		$this->assertContains( 'cf-ray: 8ab1c2d3e4f5-LHR', $result->data['evidence'] );
		$this->assertTrue( $result->data['page_cache_hit'] );
		$this->assertContains( 'cf-cache-status: HIT', $result->data['page_cache_evidence'] );
		$this->assertSame( 200, $result->data['status_code'] );
		$this->assertStringContainsString( 'CDN detected: cloudflare', $result->summary );
	}

	public function test_no_cdn_headers_pass_without_a_provider(): void {
		$this->serve(
			[
				'response' => [ 'code' => 200 ],
				'body'     => '<html><body></body></html>',
				'headers'  => [
					'content-type' => 'text/html; charset=UTF-8',
				],
			]
		);

		$result = ( new CdnDetect() )->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertNull( $result->data['provider'] );
		$this->assertSame( [], $result->data['evidence'] );
		$this->assertNull( $result->data['page_cache_hit'] );
		$this->assertStringContainsString( 'No CDN detected', $result->summary );
	}

	public function test_a_blocked_home_page_is_info_not_a_failure(): void {
		$this->serve( new \WP_Error( 'http_request_failed', 'Loopback refused.' ) );

		$result = ( new CdnDetect() )->run( Context::for_tests() );

		$this->assertSame( Status::INFO, $result->status );
		$this->assertSame( [], $result->findings );
		$this->assertNull( $result->data['provider'] );
		$this->assertSame( [], $result->data['evidence'] );
		$this->assertNull( $result->data['page_cache_hit'] );
		$this->assertSame( [], $result->data['page_cache_evidence'] );
		$this->assertNull( $result->data['status_code'] );
		$this->assertStringContainsString( 'Could not fetch the home page', $result->summary );
	}

	public function test_result_is_json_serialisable(): void {
		$this->serve(
			[
				'response' => [ 'code' => 200 ],
				'body'     => '<html><body></body></html>',
				'headers'  => [ 'cf-ray' => '8ab1c2d3e4f5-LHR' ],
			]
		);

		$result = ( new CdnDetect() )->run( Context::for_tests() );

		$this->assertIsString( wp_json_encode( $result->to_array() ) );
	}
}
