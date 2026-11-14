<?php
/**
 * Tests for the HostDetect helpers.
 *
 * @package AMC\Support
 */

declare( strict_types = 1 );

namespace AMC\Tests\Support;

use AMC\Support\HostDetect;
use PHPUnit\Framework\TestCase;

/**
 * Class HostDetectTest
 */
final class HostDetectTest extends TestCase {

	/**
	 * Lists every marker constant the detector reads, including VIP, Kinsta and Pressable.
	 */
	public function test_constant_names_lists_every_marker(): void {
		$names = HostDetect::constant_names();

		foreach ( [ 'WPCOM_IS_VIP_ENV', 'VIP_GO_APP_ENVIRONMENT', 'WPE_APIKEY', 'PWP_NAME', 'PANTHEON_ENVIRONMENT', 'KINSTAMU_VERSION', 'IS_PRESSABLE' ] as $name ) {
			$this->assertContains( $name, $names );
		}
	}

	/**
	 * Detects WordPress VIP from its environment constants.
	 */
	public function test_detects_wordpress_vip(): void {
		$result = HostDetect::platform(
			[
				'WPCOM_IS_VIP_ENV'       => true,
				'VIP_GO_APP_ENVIRONMENT' => 'production',
			],
			[ 'SERVER_SOFTWARE' => 'nginx/1.25.3' ]
		);

		$this->assertSame( 'wpcom-vip', $result['host'] );
		$this->assertContains( 'constant WPCOM_IS_VIP_ENV = true', $result['evidence'] );
		$this->assertContains( 'constant VIP_GO_APP_ENVIRONMENT = production', $result['evidence'] );
	}

	/**
	 * Detects WP Engine from either of its two markers.
	 *
	 * @dataProvider provide_wp_engine_constants
	 *
	 * @param array<string, mixed> $constants Constants map.
	 */
	public function test_detects_wp_engine( array $constants ): void {
		$this->assertSame( 'wpengine', HostDetect::platform( $constants, [] )['host'] );
	}

	/**
	 * The two WP Engine marker constants.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function provide_wp_engine_constants(): array {
		return [
			'api key' => [ [ 'WPE_APIKEY' => 'abc123def' ] ],
			'pwp'     => [ [ 'PWP_NAME' => 'customer-site' ] ],
			'both'    => [
				[
					'WPE_APIKEY' => 'abc123def',
					'PWP_NAME'   => 'customer-site',
				],
			],
		];
	}

	/**
	 * Detects Pantheon, Kinsta and Pressable.
	 *
	 * @dataProvider provide_single_marker_platforms
	 *
	 * @param array<string, mixed> $constants Constants map.
	 * @param string               $expected  Expected host name.
	 */
	public function test_detects_single_marker_platforms( array $constants, string $expected ): void {
		$this->assertSame( $expected, HostDetect::platform( $constants, [] )['host'] );
	}

	/**
	 * Single-marker platforms.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function provide_single_marker_platforms(): array {
		return [
			'pantheon'  => [ [ 'PANTHEON_ENVIRONMENT' => 'live' ], 'pantheon' ],
			'kinsta'    => [ [ 'KINSTAMU_VERSION' => '2.4.0' ], 'kinsta' ],
			'pressable' => [ [ 'IS_PRESSABLE' => true ], 'pressable' ],
		];
	}

	/**
	 * Priority: WordPress VIP wins when several platforms look present.
	 */
	public function test_vip_wins_over_other_platforms(): void {
		$constants = [
			'IS_PRESSABLE'         => true,
			'WPCOM_IS_VIP_ENV'     => true,
			'PANTHEON_ENVIRONMENT' => 'live',
		];

		$this->assertSame( 'wpcom-vip', HostDetect::platform( $constants, [] )['host'] );
	}

	/**
	 * Defined-but-false and empty markers do not count as platform evidence.
	 */
	public function test_ignores_empty_and_false_markers(): void {
		$constants = [
			'WPCOM_IS_VIP_ENV'     => false,
			'PANTHEON_ENVIRONMENT' => '',
			'IS_PRESSABLE'         => '0',
		];

		$this->assertNull( HostDetect::platform( $constants, [] )['host'] );
	}

	/**
	 * Look-alike constant names that are not the real markers are ignored.
	 */
	public function test_ignores_lookalike_constant_names(): void {
		$constants = [
			'WPE_GET_KEY'            => 'abc',
			'PWP_NAME_ALT'           => 'site',
			'VIP_GO_ENVIRONMENT'     => 'production',
			'KINSTAMU_VERSION_DRAFT' => '1.0',
		];

		$this->assertNull( HostDetect::platform( $constants, [] )['host'] );
	}

	/**
	 * Empty input gives all-null results.
	 */
	public function test_empty_input_gives_nulls_and_no_evidence(): void {
		$this->assertSame(
			[
				'host'       => null,
				'web_server' => null,
				'evidence'   => [],
			],
			HostDetect::platform( [], [] )
		);
	}

	/**
	 * Web server software is parsed from SERVER_SOFTWARE.
	 *
	 * @dataProvider provide_server_software
	 *
	 * @param string      $software Raw SERVER_SOFTWARE value.
	 * @param string|null $expected Expected server name.
	 */
	public function test_parses_web_server_software( string $software, ?string $expected ): void {
		$this->assertSame( $expected, HostDetect::platform( [], [ 'SERVER_SOFTWARE' => $software ] )['web_server'] );
	}

	/**
	 * Realistic SERVER_SOFTWARE values.
	 *
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	public static function provide_server_software(): array {
		return [
			'apache'        => [ 'Apache/2.4.57 (Unix) OpenSSL/1.1.1', 'apache' ],
			'nginx'         => [ 'nginx/1.25.3', 'nginx' ],
			'litespeed'     => [ 'LiteSpeed', 'litespeed' ],
			'openlitespeed' => [ 'OpenLiteSpeed/1.7.16', 'litespeed' ],
			'caddy'         => [ 'Caddy/2.7.6', 'caddy' ],
			'iis'           => [ 'Microsoft-IIS/10.0', 'iis' ],
			'php dev'       => [ 'PHP 8.2.0 Development Server', null ],
			'unknown'       => [ 'MyWebServer/1.0', null ],
		];
	}

	/**
	 * The SERVER_SOFTWARE line lands in the evidence log.
	 */
	public function test_server_software_is_reported_as_evidence(): void {
		$result = HostDetect::platform( [], [ 'SERVER_SOFTWARE' => 'Apache/2.4.57' ] );

		$this->assertSame( [ 'SERVER_SOFTWARE: Apache/2.4.57' ], $result['evidence'] );
	}
}
