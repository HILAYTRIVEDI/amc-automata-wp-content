<?php
/**
 * Tests for the lane B2 check provider.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Checks\Providers;

use AMC\Checks\Group;
use AMC\Checks\Registry;
use AMC\Tests\TestCase;

/**
 * Class LaneB2ChecksTest
 */
final class LaneB2ChecksTest extends TestCase {

	public function test_every_b2_check_is_registered_with_the_catalogue_group_and_schedule(): void {
		$registry = new Registry();
		$expected = [
			'vuln.match'          => [ Group::VULNERABILITIES, 'twicedaily' ],
			'errors.fatal'        => [ Group::ERRORS, 'hourly' ],
			'errors.warnings'     => [ Group::ERRORS, 'hourly' ],
			'errors.deprecations' => [ Group::ERRORS, 'hourly' ],
			'smoke.urls'          => [ Group::SMOKE, 'hourly' ],
			'security.checksums'  => [ Group::SECURITY, 'daily' ],
			'security.config'     => [ Group::SECURITY, 'daily' ],
			'security.admins'     => [ Group::SECURITY, 'daily' ],
			'ssl.certificate'     => [ Group::SECURITY, 'daily' ],
			'perf.response'       => [ Group::PERFORMANCE, 'hourly' ],
			'perf.slow_queries'   => [ Group::PERFORMANCE, 'hourly' ],
		];

		foreach ( $expected as $id => [ $group, $schedule ] ) {
			$check = $registry->get( $id );

			$this->assertNotNull( $check, $id );
			$this->assertSame( $group, $check->group(), $id );
			$this->assertSame( $schedule, $check->schedule()->value, $id );
		}
	}

	public function test_the_fixture_provider_is_off_by_default(): void {
		$this->assertInstanceOf( \AMC\Vulnerability\NullProvider::class, \AMC\Vulnerability\Providers::current() );
	}
}
