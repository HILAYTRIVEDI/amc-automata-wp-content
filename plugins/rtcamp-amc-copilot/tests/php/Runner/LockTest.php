<?php
/**
 * Tests for amc_locks based locks.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Runner;

use AMC\Runner\Lock;
use AMC\Storage\LocksRepository;
use AMC\Tests\TestCase;

/**
 * Class LockTest
 */
final class LockTest extends TestCase {

	public function test_second_holder_is_refused_until_release(): void {
		$first  = Lock::for_check( 'updates.core', 1 );
		$second = Lock::for_check( 'updates.core', 1 );

		$this->assertTrue( $first->acquire() );
		$this->assertFalse( $second->acquire() );

		$first->release();
		$this->assertTrue( $second->acquire() );
		$second->release();
	}

	public function test_locks_are_per_check_and_per_site(): void {
		$a = Lock::for_check( 'updates.core', 1 );
		$b = Lock::for_check( 'updates.core', 2 );
		$c = Lock::named( 'retention' );

		$this->assertTrue( $a->acquire() );
		$this->assertTrue( $b->acquire() );
		$this->assertTrue( $c->acquire() );
	}

	public function test_expired_lock_is_taken_over(): void {
		$repo = new LocksRepository();
		$this->assertTrue( $repo->acquire( 'check:1:x.y', 'crashed-owner', -10 ) );

		$this->assertFalse( $repo->is_locked( 'check:1:x.y' ) );
		$this->assertTrue( Lock::for_check( 'x.y', 1 )->acquire() );
	}

	public function test_ttl_is_ten_minutes(): void {
		$this->assertSame( 600, Lock::TTL );
	}
}
