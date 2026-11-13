<?php
/**
 * Tests for the HeaderDetect helpers.
 *
 * @package AMC\Support
 */

declare( strict_types = 1 );

namespace AMC\Tests\Support;

use AMC\Support\HeaderDetect;
use PHPUnit\Framework\TestCase;

/**
 * Class HeaderDetectTest
 */
final class HeaderDetectTest extends TestCase {

	/**
	 * A realistic Cloudflare response with a cache hit.
	 *
	 * @return array<string, mixed>
	 */
	private function cloudflare_hit_headers(): array {
		return [
			'date'            => 'Fri, 13 Nov 2026 10:00:00 GMT',
			'content-type'    => 'text/html; charset=UTF-8',
			'cf-ray'          => '8ab1c2d3e4f5gath-LHR',
			'cf-cache-status' => 'HIT',
			'age'             => '240',
			'server'          => 'cloudflare',
			'vary'            => 'Accept-Encoding',
		];
	}

	/**
	 * Detects Cloudflare and keeps corroborating evidence lines.
	 */
	public function test_detects_cloudflare(): void {
		$result = HeaderDetect::cdn( $this->cloudflare_hit_headers() );

		$this->assertSame( 'cloudflare', $result['provider'] );
		$this->assertStringContainsString( 'cf-ray: 8ab1c2d3e4f5gath-LHR', $result['evidence'][0] );
		$this->assertContains( 'server: cloudflare', $result['evidence'] );
	}

	/**
	 * Detects Fastly from a fastly-marked served-by header.
	 */
	public function test_detects_fastly(): void {
		$headers = [
			'x-served-by'  => 'cache-lhr7381-LHR, Fastly',
			'x-cache'      => 'HIT',
			'x-cache-hits' => '1',
			'server'       => 'nginx/1.25.3',
		];

		$result = HeaderDetect::cdn( $headers );

		$this->assertSame( 'fastly', $result['provider'] );
		$this->assertContains( 'x-served-by: cache-lhr7381-LHR, Fastly', $result['evidence'] );
	}

	/**
	 * Detects CloudFront from its own headers even when x-cache says miss.
	 */
	public function test_detects_cloudfront(): void {
		$headers = [
			'x-amz-cf-id'  => 'kQo2XjZy0pQ_1hXwZ0n3Lg==',
			'x-amz-cf-pop' => 'LHR62-C3',
			'x-cache'      => 'Miss from cloudfront',
			'via'          => '1.1 9a1b2c3d.cloudfront.net (CloudFront)',
		];

		$result = HeaderDetect::cdn( $headers );

		$this->assertSame( 'cloudfront', $result['provider'] );
		$this->assertStringContainsString( 'x-amz-cf-id: kQo2XjZy0pQ_1hXwZ0n3Lg==', $result['evidence'][0] );
		$this->assertContains( 'x-cache: Miss from cloudfront', $result['evidence'] );
	}

	/**
	 * Detects Akamai from its prefixed headers and the server hint.
	 */
	public function test_detects_akamai(): void {
		$headers = [
			'x-akamai-transformed' => '9 - 0 pmb=TOE-2',
			'server'               => 'AkamaiGHost',
		];

		$result = HeaderDetect::cdn( $headers );

		$this->assertSame( 'akamai', $result['provider'] );
		$this->assertContains( 'server: AkamaiGHost', $result['evidence'] );
	}

	/**
	 * Detects Sucuri from its WAF headers.
	 */
	public function test_detects_sucuri(): void {
		$headers = [
			'x-sucuri-id'    => '11007',
			'x-sucuri-cache' => 'MISS',
			'server'         => 'Sucuri/Cloudproxy',
		];

		$result = HeaderDetect::cdn( $headers );

		$this->assertSame( 'sucuri', $result['provider'] );
		$this->assertContains( 'x-sucuri-id: 11007', $result['evidence'] );
	}

	/**
	 * Detects Vercel from its prefixed headers.
	 */
	public function test_detects_vercel(): void {
		$headers = [
			'x-vercel-cache' => 'HIT',
			'x-vercel-id'    => 'lhr1:lhr1::aaaa:0',
			'server'         => 'Vercel',
		];

		$result = HeaderDetect::cdn( $headers );

		$this->assertSame( 'vercel', $result['provider'] );
		$this->assertContains( 'x-vercel-cache: HIT', $result['evidence'] );
	}

	/**
	 * Plain nginx headers with no CDN signature give no provider.
	 */
	public function test_returns_null_for_plain_nginx(): void {
		$headers = [
			'server'       => 'nginx/1.25.3',
			'x-powered-by' => 'PHP/8.2',
			'x-served-by'  => 'app-01',
		];

		$this->assertSame(
			[
				'provider' => null,
				'evidence' => [],
			],
			HeaderDetect::cdn( $headers )
		);
	}

	/**
	 * A x-served-by cache id that only looks like a CDN is not a match.
	 */
	public function test_ignores_lookalike_served_by_values(): void {
		$headers = [
			'x-served-by' => 'cache-lhr7381-LHR',
			'x-cache'     => 'HIT',
		];

		$this->assertNull( HeaderDetect::cdn( $headers )['provider'] );
	}

	/**
	 * Cloudflare wins over the generic server hint when both are present.
	 */
	public function test_priority_prefers_explicit_cdn_headers(): void {
		$headers = [
			'cf-ray' => '8ab1c2d3-LHR',
			'server' => 'nginx/1.25.3',
		];

		$this->assertSame( 'cloudflare', HeaderDetect::cdn( $headers )['provider'] );
	}

	/**
	 * Empty input gives no provider.
	 */
	public function test_empty_headers_return_null_provider(): void {
		$this->assertSame(
			[
				'provider' => null,
				'evidence' => [],
			],
			HeaderDetect::cdn( [] )
		);
	}

	/**
	 * Values passed as lists of strings (curl/Guzzle shape) still work.
	 */
	public function test_accepts_list_shaped_header_values(): void {
		$headers = [
			'cf-ray' => [ '8ab1c2d3-LHR' ],
		];

		$this->assertSame( 'cloudflare', HeaderDetect::cdn( $headers )['provider'] );
	}

	/**
	 * Cache hit/miss detection from realistic header sets.
	 *
	 * @dataProvider provide_page_cache_cases
	 *
	 * @param array<string, mixed> $headers     Lower-cased response headers.
	 * @param bool|null            $expected    Expected hit decision.
	 * @param string               $description What the fixture represents.
	 */
	public function test_page_cache( array $headers, ?bool $expected, string $description ): void {
		$result = HeaderDetect::page_cache( $headers );

		$this->assertSame( $expected, $result['hit'], $description );
	}

	/**
	 * Realistic page-cache fixtures.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: bool|null, 2: string}>
	 */
	public static function provide_page_cache_cases(): array {
		return [
			'fastly hit'            => [
				[
					'x-cache'     => 'HIT',
					'x-served-by' => 'cache-lhr-Fastly',
				],
				true,
				'Fastly reports a hit.',
			],
			'cloudfront miss'       => [ [ 'x-cache' => 'Miss from cloudfront' ], false, 'CloudFront reports a miss.' ],
			'varnish tcp hit'       => [ [ 'x-cache' => 'TCP_HIT' ], true, 'Varnish TCP_HIT token contains hit.' ],
			'whitney lookalike'     => [ [ 'x-cache' => 'whitney county fair' ], null, 'A word merely containing hit/miss letters is ignored.' ],
			'cf hit'                => [ [ 'cf-cache-status' => 'HIT' ], true, 'Cloudflare cache hit.' ],
			'cf stale'              => [ [ 'cf-cache-status' => 'STALE' ], true, 'Stale content was still served from cache.' ],
			'cf miss with age zero' => [
				[
					'cf-cache-status' => 'MISS',
					'age'             => '0',
				],
				false,
				'Explicit miss wins over a zero age.',
			],
			'cf dynamic'            => [ [ 'cf-cache-status' => 'DYNAMIC' ], false, 'DYNAMIC means the edge did not cache the page.' ],
			'proxy cache miss'      => [ [ 'x-proxy-cache' => 'MISS' ], false, 'Nginx proxy cache miss.' ],
			'litespeed hit'         => [ [ 'x-litespeed-cache' => 'hit' ], true, 'LiteSpeed cache hit.' ],
			'age only'              => [ [ 'age' => '240' ], true, 'Non-zero age with no status header indicates cache.' ],
			'age zero only'         => [ [ 'age' => '0' ], null, 'Zero age is inconclusive.' ],
			'no cache headers'      => [ [ 'server' => 'nginx/1.25.3' ], null, 'No cache evidence at all.' ],
			'empty'                 => [ [], null, 'Empty header map.' ],
		];
	}

	/**
	 * The winning cache decision carries its header as evidence.
	 */
	public function test_page_cache_evidence_names_the_deciding_header(): void {
		$result = HeaderDetect::page_cache( [ 'cf-cache-status' => 'HIT' ] );

		$this->assertSame( [ 'cf-cache-status: HIT' ], $result['evidence'] );
	}
}
