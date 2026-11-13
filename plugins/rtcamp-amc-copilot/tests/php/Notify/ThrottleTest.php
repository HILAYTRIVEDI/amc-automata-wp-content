<?php
/**
 * Tests for Throttle.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Notify;

use AMC\Core\Settings;
use AMC\Notify\Throttle;
use AMC\Tests\TestCase;

/**
 * Class ThrottleTest
 */
final class ThrottleTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		delete_option( Throttle::OPTION );
	}

	public function test_default_cap_is_ten_per_hour(): void {
		$clock      = new \stdClass();
		$clock->now = 1_000_000;
		$throttle   = new Throttle( static fn (): int => $clock->now );

		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertTrue( $throttle->acquire() );
		}

		$this->assertFalse( $throttle->acquire() );
		$this->assertSame( 0, $throttle->remaining() );
	}

	public function test_slots_free_up_after_an_hour(): void {
		$clock      = new \stdClass();
		$clock->now = 1_000_000;
		$throttle   = new Throttle( static fn (): int => $clock->now );

		Settings::update( [ 'immediate_alerts_per_hour' => 2 ] );
		$this->assertTrue( $throttle->acquire() );
		$clock->now += 1800;
		$this->assertTrue( $throttle->acquire() );
		$this->assertFalse( $throttle->acquire() );

		$clock->now += 1801; // First send is now 3601 s old.
		$this->assertSame( 1, $throttle->used() );
		$this->assertTrue( $throttle->acquire() );
	}

	public function test_zero_cap_sends_nothing(): void {
		Settings::update( [ 'immediate_alerts_per_hour' => 0 ] );

		$this->assertFalse( ( new Throttle() )->acquire() );
	}

	public function test_deactivation_clears_the_counter(): void {
		( new Throttle() )->acquire();

		do_action( 'amc_deactivate' );

		$this->assertFalse( get_option( Throttle::OPTION ) );
	}

	public function test_counter_is_not_autoloaded_and_ignores_object_cache(): void {
		( new Throttle() )->acquire();
		wp_cache_flush();

		$this->assertSame( 1, ( new Throttle() )->used() );

		$this->assertArrayNotHasKey( Throttle::OPTION, wp_load_alloptions( true ) );
	}
}
