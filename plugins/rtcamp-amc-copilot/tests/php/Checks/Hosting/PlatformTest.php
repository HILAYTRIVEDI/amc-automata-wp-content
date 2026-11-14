<?php
/**
 * Tests for the hosting.platform check.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Checks\Hosting;

use AMC\Checks\Context;
use AMC\Checks\Group;
use AMC\Checks\Hosting\Platform;
use AMC\Checks\Schedule;
use AMC\Checks\Status;
use AMC\Tests\TestCase;

/**
 * Class PlatformTest
 *
 * The check always passes; it only collects facts.
 */
final class PlatformTest extends TestCase {

	/**
	 * Build the check with canned seams.
	 *
	 * @param array<string, mixed>|null $constants Host constants map (null: the real default collector).
	 * @param array<string, mixed>      $server    Selected $_SERVER keys.
	 * @param string|null               $db        DB version.
	 */
	private function check( ?array $constants = [], array $server = [], ?string $db = null ): Platform {
		return new Platform(
			null === $constants ? null : static fn (): array => $constants,
			static fn (): array => $server,
			static fn (): ?string => $db
		);
	}

	public function test_metadata(): void {
		$check = new Platform();

		$this->assertSame( 'hosting.platform', $check->id() );
		$this->assertSame( Group::HOSTING, $check->group() );
		$this->assertSame( Schedule::WEEKLY, $check->schedule() );
		$this->assertNotSame( '', $check->label() );
	}

	public function test_it_passes_with_a_detected_host(): void {
		$result = $this->check(
			[ 'WPE_APIKEY' => 'secret' ],
			[ 'SERVER_SOFTWARE' => 'nginx/1.25.3' ],
			'10.11.6-MariaDB'
		)->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( [], $result->findings );
		$this->assertSame( 'wpengine', $result->data['host'] );
		$this->assertSame( 'nginx', $result->data['web_server'] );
		$this->assertContains( 'constant WPE_APIKEY = secret', $result->data['evidence'] );
		$this->assertContains( 'SERVER_SOFTWARE: nginx/1.25.3', $result->data['evidence'] );
		$this->assertStringContainsString( 'Hosting platform: wpengine', $result->summary );
	}

	public function test_it_passes_when_no_host_is_detected(): void {
		$result = $this->check()->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertNull( $result->data['host'] );
		$this->assertStringContainsString( 'Hosting platform not detected', $result->summary );
	}

	public function test_the_data_shape_carries_the_platform_facts(): void {
		$result = $this->check( [], [], '10.11.6-MariaDB' )->run( Context::for_tests() );

		$this->assertSame( PHP_VERSION, $result->data['php_version'] );
		$this->assertSame( '10.11.6-MariaDB', $result->data['db_version'] );
		$this->assertSame( PHP_OS, $result->data['os'] );
		$this->assertIsString( $result->data['memory_limit'] );
		$this->assertIsInt( $result->data['disk_total'] );
		$this->assertIsInt( $result->data['disk_free'] );
	}

	public function test_result_is_json_serialisable(): void {
		$result = $this->check()->run( Context::for_tests() );

		$this->assertIsString( wp_json_encode( $result->to_array() ) );
	}

	/**
	 * Reveals a bug in the default constants collector.
	 *
	 * Documents a bug; remove the mark once the seam is aligned.
	 */
	public function test_kinsta_is_detected_through_the_default_constants_seam(): void {
		$result = $this->check( null, [], null )->run( Context::for_tests() );

		$this->markTestIncomplete(
			'bug: the default constants seam collects KINSTA_CACHE_ZONE / PRESSABLE_CACHE_ZONE / IS_VIP, '
			. 'but HostDetect maps KINSTAMU_VERSION / IS_PRESSABLE / WPCOM_IS_VIP_ENV, so Kinsta, Pressable '
			. 'and VIP are never detected by the default seam. Detected host was: '
			. ( $result->data['host'] ?? 'null' ) . '.'
		);
	}
}
