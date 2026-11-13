<?php
/**
 * Tests for the perf.cache check.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Checks\Performance;

use AMC\Checks\Context;
use AMC\Checks\Group;
use AMC\Checks\Performance\CacheCheck;
use AMC\Checks\Schedule;
use AMC\Checks\Status;
use AMC\Tests\TestCase;

/**
 * Class CacheCheckTest
 *
 * The seams carry canned Site Health results; one integration test runs core's
 * real tests with the HTTP layer short-circuited.
 */
final class CacheCheckTest extends TestCase {

	/**
	 * Build the check with canned seams.
	 *
	 * @param bool                      $object_cache Whether an external object cache is in use.
	 * @param array<string, mixed>|null $health       What the Site Health seam returns (null: unavailable).
	 * @param mixed                     $home_page    What the home page fetch fallback returns.
	 */
	private function check( bool $object_cache = false, ?array $health = null, $home_page = null ): CacheCheck {
		return new CacheCheck(
			static fn (): bool => $object_cache,
			static fn (): mixed => $home_page ?? new \WP_Error( 'http_request_failed', 'No fetch expected.' ),
			static fn (): ?array => $health
		);
	}

	/**
	 * A canned Site Health result.
	 *
	 * @param mixed  $detail        Page cache detail (array, WP_Error or null).
	 * @param string $page_status   Site Health page cache status.
	 * @param string $object_status Site Health object cache status.
	 *
	 * @return array<string, mixed>
	 */
	private function health( $detail, string $page_status = 'good', string $object_status = 'recommended' ): array {
		return [
			'page_cache'   => [
				'status' => $page_status,
				'label'  => '<b>Page cache is detected</b> and the server response time is good',
			],
			'object_cache' => [
				'status' => $object_status,
				'label'  => 'You should use a persistent object cache',
			],
			'detail'       => $detail,
		];
	}

	public function test_metadata(): void {
		$check = new CacheCheck();

		$this->assertSame( 'perf.cache', $check->id() );
		$this->assertSame( Group::PERFORMANCE, $check->group() );
		$this->assertSame( Schedule::DAILY, $check->schedule() );
		$this->assertNotSame( '', $check->label() );
	}

	public function test_page_cache_headers_from_site_health_are_a_hit_with_the_documented_data(): void {
		$result = $this->check(
			false,
			$this->health(
				[
					'status'                 => 'good',
					'advanced_cache_present' => false,
					'headers'                => [ 'cache-control', 'etag' ],
					'response_time'          => 123.6,
				]
			)
		)->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame(
			[
				'persistent_object_cache' => false,
				'page_cache_hit'          => true,
				'page_cache_evidence'     => [ 'response header: cache-control', 'response header: etag' ],
				'page_cache_status'       => 'good',
				'page_cache_label'        => 'Page cache is detected and the server response time is good',
				'object_cache_status'     => 'recommended',
				'object_cache_label'      => 'You should use a persistent object cache',
				'response_time_ms'        => 124,
				'advanced_cache_present'  => false,
			],
			$result->data
		);
	}

	public function test_an_advanced_cache_drop_in_alone_is_a_hit(): void {
		$result = $this->check(
			false,
			$this->health(
				[
					'status'                 => 'good',
					'advanced_cache_present' => true,
					'headers'                => [],
					'response_time'          => 80,
				]
			)
		)->run( Context::for_tests() );

		$this->assertTrue( $result->data['page_cache_hit'] );
		$this->assertSame( [ 'advanced-cache.php drop-in present' ], $result->data['page_cache_evidence'] );
		$this->assertTrue( $result->data['advanced_cache_present'] );
	}

	public function test_no_page_cache_and_no_object_cache_is_info(): void {
		$result = $this->check(
			false,
			$this->health(
				[
					'status'                 => 'critical',
					'advanced_cache_present' => false,
					'headers'                => [],
					'response_time'          => 900,
				],
				'critical'
			)
		)->run( Context::for_tests() );

		$this->assertSame( Status::INFO, $result->status );
		$this->assertFalse( $result->data['page_cache_hit'] );
		$this->assertSame( [], $result->data['page_cache_evidence'] );
		$this->assertSame( 'critical', $result->data['page_cache_status'] );
		$this->assertSame( 900, $result->data['response_time_ms'] );
	}

	public function test_a_persistent_object_cache_passes_even_without_a_page_cache(): void {
		$result = $this->check(
			true,
			$this->health(
				[
					'status'                 => 'recommended',
					'advanced_cache_present' => false,
					'headers'                => [],
					'response_time'          => 200,
				],
				'recommended',
				'good'
			)
		)->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertTrue( $result->data['persistent_object_cache'] );
		$this->assertFalse( $result->data['page_cache_hit'] );
		$this->assertSame( 'good', $result->data['object_cache_status'] );
	}

	public function test_a_loopback_error_leaves_the_page_cache_undetermined_without_fetching(): void {
		$result = $this->check(
			false,
			$this->health( new \WP_Error( 'http_request_failed', 'cURL error 28' ), 'recommended' ),
			// A fallback fetch would report a hit; Site Health's WP_Error must win.
			[
				'headers'  => [ 'cf-cache-status' => 'HIT' ],
				'response' => [ 'code' => 200 ],
			]
		)->run( Context::for_tests() );

		$this->assertSame( Status::INFO, $result->status );
		$this->assertNull( $result->data['page_cache_hit'] );
		$this->assertSame( [], $result->data['page_cache_evidence'] );
		$this->assertNull( $result->data['response_time_ms'] );
		$this->assertNull( $result->data['advanced_cache_present'] );
		$this->assertSame( 'recommended', $result->data['page_cache_status'] );
	}

	public function test_without_site_health_the_home_page_headers_are_the_fallback(): void {
		$result = $this->check(
			false,
			null,
			[
				'headers'  => [ 'cf-cache-status' => 'HIT' ],
				'response' => [ 'code' => 200 ],
			]
		)->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertTrue( $result->data['page_cache_hit'] );
		$this->assertContains( 'cf-cache-status: HIT', $result->data['page_cache_evidence'] );
		$this->assertNull( $result->data['page_cache_status'] );
		$this->assertNull( $result->data['page_cache_label'] );
		$this->assertNull( $result->data['object_cache_status'] );
		$this->assertNull( $result->data['response_time_ms'] );
	}

	public function test_a_failed_fallback_fetch_leaves_the_page_cache_undetermined(): void {
		$result = $this->check( false, null )->run( Context::for_tests() );

		$this->assertSame( Status::INFO, $result->status );
		$this->assertNull( $result->data['page_cache_hit'] );
	}

	public function test_the_real_site_health_tests_feed_the_data(): void {
		$replay = static function (): array {
			return [
				'headers'  => [
					'cache-control' => 'public, max-age=600',
					'etag'          => '"abc"',
				],
				'body'     => '<html></html>',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'cookies'  => [],
				'filename' => null,
			];
		};
		add_filter( 'pre_http_request', $replay );

		try {
			$result = ( new CacheCheck() )->run( Context::for_tests() );
		} finally {
			remove_filter( 'pre_http_request', $replay );
		}

		$this->assertTrue( $result->data['page_cache_hit'] );
		$this->assertContains( 'response header: etag', $result->data['page_cache_evidence'] );
		$this->assertContains( 'response header: cache-control', $result->data['page_cache_evidence'] );
		$this->assertContains( $result->data['page_cache_status'], [ 'good', 'recommended', 'critical' ] );
		$this->assertNotSame( '', $result->data['page_cache_label'] );
		$this->assertContains( $result->data['object_cache_status'], [ 'good', 'recommended' ] );
		$this->assertNotSame( '', $result->data['object_cache_label'] );
		$this->assertIsInt( $result->data['response_time_ms'] );
		$this->assertFalse( $result->data['advanced_cache_present'] );
		$this->assertSame( (bool) wp_using_ext_object_cache(), $result->data['persistent_object_cache'] );
	}

	public function test_result_is_json_serialisable(): void {
		$result = $this->check( false, $this->health( new \WP_Error( 'x', 'y' ) ) )->run( Context::for_tests() );

		$this->assertIsString( wp_json_encode( $result->to_array() ) );
	}
}
