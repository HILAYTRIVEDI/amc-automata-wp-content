<?php
/**
 * Tests for Digest (content, schedule, lock).
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Notify;

use AMC\Checks\Severity;
use AMC\Core\Settings;
use AMC\Notify\Digest;
use AMC\Runner\Lock;
use AMC\Storage\Tables;

/**
 * Class DigestTest
 */
final class DigestTest extends NotifyTestCase {

	public function set_up(): void {
		parent::set_up();

		wp_clear_scheduled_hook( Digest::HOOK );
	}

	/**
	 * Make a finding look older.
	 *
	 * @param array<string, mixed> $row   Finding row.
	 * @param int                  $hours Hours ago it was first seen.
	 */
	private function age( array $row, int $hours ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test setup: backdate a row.
		$wpdb->update( Tables::name( Tables::FINDINGS ), [ 'first_seen' => gmdate( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS ) ], [ 'id' => $row['id'] ] );
	}

	public function test_nothing_new_sends_nothing(): void {
		$this->assertFalse( ( new Digest() )->run() );
		$this->assertSame( 0, $this->sent_count() );
	}

	public function test_still_open_alone_does_not_trigger_a_digest(): void {
		$old = $this->make_finding( 'updates.plugins', 'old', Severity::MEDIUM );
		$this->age( $old, 72 );

		$this->assertFalse( ( new Digest() )->run() );
		$this->assertSame( 0, $this->sent_count() );
	}

	public function test_none_routes_and_non_open_findings_do_not_trigger_a_digest(): void {
		$this->make_finding( 'detect.cdn', 'c', Severity::INFO );
		$muted = $this->make_finding( 'updates.core', 'm', Severity::MEDIUM );
		$this->findings->set_status( (int) $muted['id'], 'muted' );

		$this->assertFalse( ( new Digest() )->run() );
	}

	public function test_new_digest_finding_is_sent_once_then_listed_as_open(): void {
		$new = $this->make_finding( 'updates.plugins', 'p1', Severity::MEDIUM );

		$this->assertTrue( ( new Digest() )->run() );
		$this->assertSame( 1, $this->sent_count() );
		$this->assertStringContainsString( 'Daily digest: 1 new finding', $this->sent()->subject );
		$this->assertStringStartsWith( '[AMC][', $this->sent()->subject );
		$this->assertStringContainsString( 'NEW IN THE LAST 24 HOURS (1)', $this->mime() );
		$this->assertNotNull( $this->findings->find( (int) $new['id'] )['alerted_at'] );

		// Next day: nothing new, so nothing is sent even though it is still open.
		$this->assertFalse( ( new Digest() )->run() );
		$this->assertSame( 1, $this->sent_count() );

		// Another new finding: the first one now shows as still open.
		$this->make_finding( 'updates.plugins', 'p2', Severity::LOW );
		$this->assertTrue( ( new Digest() )->run() );
		$mime = $this->mime( 1 );
		$this->assertStringContainsString( 'STILL OPEN (1)', $mime );
		$this->assertStringContainsString( 'Title p1', $mime );
	}

	public function test_findings_older_than_24_hours_are_not_new(): void {
		$old = $this->make_finding( 'updates.plugins', 'old', Severity::MEDIUM );
		$this->age( $old, 30 );
		$this->make_finding( 'updates.plugins', 'fresh', Severity::LOW );

		( new Digest() )->run();

		[ $new, $open ] = explode( 'STILL OPEN', $this->mime() );
		$this->assertStringContainsString( 'Title fresh', $new );
		$this->assertStringNotContainsString( 'Title old', $new );
		$this->assertStringContainsString( 'Title old', $open );
	}

	public function test_accepted_and_resolved_findings_are_left_out(): void {
		$this->make_finding( 'updates.plugins', 'new', Severity::LOW );
		$accepted = $this->make_finding( 'updates.themes', 'acc', Severity::MEDIUM );
		$this->findings->set_status( (int) $accepted['id'], 'accepted' );
		$resolved = $this->make_finding( 'updates.core', 'res', Severity::MEDIUM );
		$this->findings->resolve( [ (int) $resolved['id'] ] );

		( new Digest() )->run();

		$this->assertStringNotContainsString( 'Title acc', $this->mime() );
		$this->assertStringNotContainsString( 'Title res', $this->mime() );
	}

	public function test_digest_is_multipart_and_worst_first(): void {
		$this->make_finding( 'updates.plugins', 'low', Severity::LOW );
		$this->make_finding( 'updates.themes', 'high', Severity::HIGH );

		( new Digest() )->run();

		$this->assertStringContainsString( 'multipart/alternative', $this->sent()->header );
		$this->assertStringContainsString( 'text/html', $this->mime() );
		$this->assertLessThan( strpos( $this->mime(), 'Title low' ), strpos( $this->mime(), 'Title high' ) );
	}

	public function test_a_doubled_cron_run_cannot_send_twice(): void {
		$this->make_finding( 'updates.plugins', 'p1', Severity::MEDIUM );
		$held = Lock::named( 'digest' );
		$this->assertTrue( $held->acquire(), 'the other run holds the lock' );

		$this->assertFalse( ( new Digest() )->run() );
		$this->assertSame( 0, $this->sent_count() );

		$held->release();
		$this->assertTrue( ( new Digest() )->run() );
		$this->assertFalse( ( new Digest() )->run() );
		$this->assertSame( 1, $this->sent_count() );
	}

	public function test_lock_is_released_after_a_run(): void {
		( new Digest() )->run();

		$this->assertTrue( Lock::named( 'digest' )->acquire() );
	}

	public function test_kill_switch_and_missing_recipients_send_nothing(): void {
		$row = $this->make_finding( 'updates.plugins', 'p1', Severity::MEDIUM );

		Settings::update( [ 'kill_switch' => true ] );
		$this->assertFalse( ( new Digest() )->run() );

		Settings::update(
			[
				'kill_switch' => false,
				'recipients'  => [],
			]
		);
		$this->assertFalse( ( new Digest() )->run() );
		$this->assertNull( $this->findings->find( (int) $row['id'] )['alerted_at'], 'kept for the next digest' );
	}

	public function test_long_sections_are_capped_with_a_count(): void {
		for ( $i = 1; $i <= Digest::LIST_LIMIT + 5; $i++ ) {
			$this->make_finding( 'updates.plugins', 'k' . $i, Severity::LOW );
		}

		( new Digest() )->run();

		$this->assertStringContainsString( '… and 5 more', $this->mime() );
		$this->assertStringContainsString( '(30)', $this->mime() );
	}

	/**
	 * Local time of a timestamp.
	 *
	 * @param int    $timestamp Unix time.
	 * @param string $zone      Timezone name.
	 */
	private function local( int $timestamp, string $zone ): string {
		return ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( new \DateTimeZone( $zone ) )->format( 'Y-m-d H:i T' );
	}

	public function test_next_run_is_nine_site_time_in_utc(): void {
		$from = ( new \DateTimeImmutable( '2026-06-10 02:00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();

		$this->assertSame( '2026-06-10 09:00 UTC', $this->local( Digest::next_run( '09:00', $from, new \DateTimeZone( 'UTC' ) ), 'UTC' ) );
	}

	public function test_next_run_uses_the_site_timezone_not_utc(): void {
		$tz   = new \DateTimeZone( 'Asia/Kolkata' );
		$from = ( new \DateTimeImmutable( '2026-06-10 00:00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();

		$next = Digest::next_run( '09:00', $from, $tz );

		$this->assertSame( '2026-06-10 09:00 IST', $this->local( $next, 'Asia/Kolkata' ) );
		$this->assertSame( '2026-06-10 03:30', gmdate( 'Y-m-d H:i', $next ) );
	}

	public function test_next_run_moves_to_tomorrow_once_today_has_passed(): void {
		$tz   = new \DateTimeZone( 'America/New_York' );
		$from = ( new \DateTimeImmutable( '2026-06-10 09:00:00', $tz ) )->getTimestamp(); // Exactly 09:00: not "after".

		$this->assertSame( '2026-06-11 09:00 EDT', $this->local( Digest::next_run( '09:00', $from, $tz ), 'America/New_York' ) );
	}

	public function test_next_run_stays_at_nine_across_spring_forward(): void {
		$tz   = new \DateTimeZone( 'America/New_York' );
		$from = ( new \DateTimeImmutable( '2026-03-07 10:00:00', $tz ) )->getTimestamp(); // EST, day before DST starts.

		$next = Digest::next_run( '09:00', $from, $tz );

		$this->assertSame( '2026-03-08 09:00 EDT', $this->local( $next, 'America/New_York' ) );
		$this->assertSame( '2026-03-08 13:00', gmdate( 'Y-m-d H:i', $next ), 'only 23 hours after the previous 09:00 EST (14:00 UTC)' );
	}

	public function test_next_run_stays_at_nine_across_fall_back(): void {
		$tz   = new \DateTimeZone( 'America/New_York' );
		$from = ( new \DateTimeImmutable( '2026-10-31 10:00:00', $tz ) )->getTimestamp(); // EDT, day before DST ends.

		$next = Digest::next_run( '09:00', $from, $tz );

		$this->assertSame( '2026-11-01 09:00 EST', $this->local( $next, 'America/New_York' ) );
		$this->assertSame( '2026-11-01 14:00', gmdate( 'Y-m-d H:i', $next ) );
	}

	public function test_next_run_honours_a_custom_time_and_falls_back_on_garbage(): void {
		$tz   = new \DateTimeZone( 'UTC' );
		$from = ( new \DateTimeImmutable( '2026-06-10 02:00:00', $tz ) )->getTimestamp();

		$this->assertSame( '2026-06-10 17:45 UTC', $this->local( Digest::next_run( '17:45', $from, $tz ), 'UTC' ) );
		$this->assertSame( '2026-06-10 09:00 UTC', $this->local( Digest::next_run( 'nonsense', $from, $tz ), 'UTC' ) );
	}

	public function test_schedule_creates_one_event_at_site_nine_am(): void {
		update_option( 'timezone_string', 'Asia/Kolkata' );
		wp_clear_scheduled_hook( Digest::HOOK );

		Digest::schedule();
		Digest::schedule();

		$next = wp_next_scheduled( Digest::HOOK );
		$this->assertNotFalse( $next );
		$this->assertSame( '09:00', ( new \DateTimeImmutable( '@' . $next ) )->setTimezone( new \DateTimeZone( 'Asia/Kolkata' ) )->format( 'H:i' ) );
		$this->assertCount( 1, array_filter( _get_cron_array(), static fn ( array $events ): bool => isset( $events[ Digest::HOOK ] ) ) );
	}

	public function test_activation_schedules_and_deactivation_clears(): void {
		do_action( 'amc_activate' );
		$this->assertNotFalse( wp_next_scheduled( Digest::HOOK ) );

		do_action( 'amc_deactivate' );
		$this->assertFalse( wp_next_scheduled( Digest::HOOK ) );
	}

	public function test_changing_the_digest_time_reschedules(): void {
		update_option( 'timezone_string', 'UTC' );
		Digest::schedule();

		Settings::update( [ 'digest_time' => '17:30' ] );

		$next = wp_next_scheduled( Digest::HOOK );
		$this->assertSame( '17:30', gmdate( 'H:i', (int) $next ) );
	}

	public function test_changing_the_timezone_reschedules(): void {
		update_option( 'timezone_string', 'UTC' );
		Digest::schedule();

		update_option( 'timezone_string', 'Asia/Kolkata' );

		$this->assertSame( '03:30', gmdate( 'H:i', (int) wp_next_scheduled( Digest::HOOK ) ) );
	}

	public function test_cron_callback_chains_the_next_day_even_when_nothing_is_sent(): void {
		update_option( 'timezone_string', 'UTC' );
		wp_clear_scheduled_hook( Digest::HOOK );

		( new Digest() )->on_cron();

		$this->assertNotFalse( wp_next_scheduled( Digest::HOOK ) );
		$this->assertSame( 0, $this->sent_count() );
	}

	public function test_sample_digest_is_sent_to_the_recipients(): void {
		$this->assertTrue( Digest::send_sample() );
		$this->assertStringContainsString( 'Test: daily digest', $this->sent()->subject );
	}
}
