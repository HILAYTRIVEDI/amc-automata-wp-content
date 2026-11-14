<?php
/**
 * Tests for the CoreSiteHealth helper.
 *
 * @package AMC\Support
 */

declare( strict_types = 1 );

namespace AMC\Tests\Support;

use AMC\Support\CoreSiteHealth;
use AMC\Tests\TestCase;

/**
 * Class CoreSiteHealthTest
 */
final class CoreSiteHealthTest extends TestCase {

	public function test_it_returns_core_site_health_instance(): void {
		$this->assertInstanceOf( \WP_Site_Health::class, CoreSiteHealth::instance() );
		$this->assertSame( CoreSiteHealth::instance(), \WP_Site_Health::get_instance() );
	}

	public function test_it_caps_http_timeouts_only_while_the_callback_runs(): void {
		$seen = static fn ( int $timeout ): float => (float) apply_filters( 'http_request_args', [ 'timeout' => $timeout ], 'https://example.org' )['timeout']; // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Not a request: the filter is invoked directly.

		$inside = CoreSiteHealth::with_http_timeout( static fn (): array => [ $seen( 30 ), $seen( 2 ) ], 5 );

		$this->assertSame( [ 5.0, 2.0 ], $inside );
		$this->assertSame( 30.0, $seen( 30 ) );
	}

	public function test_it_removes_the_cap_when_the_callback_throws(): void {
		try {
			CoreSiteHealth::with_http_timeout(
				static function (): void {
					throw new \RuntimeException( 'boom' );
				},
				5
			);
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}

		$args = apply_filters( 'http_request_args', [ 'timeout' => 30 ], 'https://example.org' ); // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Not a request: the filter is invoked directly.
		$this->assertSame( 30, $args['timeout'] );
	}
}
