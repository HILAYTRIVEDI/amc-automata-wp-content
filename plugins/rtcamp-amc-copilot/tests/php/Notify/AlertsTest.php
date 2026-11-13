<?php
/**
 * Tests for Alerts (immediate alerts, alert-once, throttle).
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Notify;

use AMC\Checks\Finding;
use AMC\Checks\Result;
use AMC\Checks\Severity;
use AMC\Core\Settings;
use AMC\Notify\Alerts;
use AMC\Notify\Digest;
use AMC\Runner\Runner;
use AMC\Tests\Support\FakeCheck;

/**
 * Class AlertsTest
 */
final class AlertsTest extends NotifyTestCase {

	public function test_immediate_finding_sends_one_email_and_is_marked_alerted(): void {
		$row = $this->make_finding( 'errors.fatal', 'sig1', Severity::CRITICAL );

		$this->assertTrue( ( new Alerts() )->alert( $this->processed( $row ) ) );

		$this->assertSame( 1, $this->sent_count() );
		$this->assertNotNull( $this->findings->find( (int) $row['id'] )['alerted_at'] );
		$this->assertStringContainsString( 'Title sig1', $this->sent()->subject );
	}

	public function test_digest_and_none_routes_send_nothing_and_stay_unalerted(): void {
		$digest = $this->make_finding( 'updates.plugins', 'p1', Severity::MEDIUM );
		$none   = $this->make_finding( 'detect.cdn', 'c1', Severity::INFO );

		$alerts = new Alerts();
		$alerts->alert( $this->processed( $digest ) );
		$alerts->alert( $this->processed( $none ) );

		$this->assertSame( 0, $this->sent_count() );
		$this->assertNull( $this->findings->find( (int) $digest['id'] )['alerted_at'] );
	}

	public function test_medium_vulnerability_waits_for_digest_critical_does_not(): void {
		$alerts = new Alerts();

		$alerts->alert( $this->processed( $this->make_finding( 'vuln.match', 'a', Severity::MEDIUM ) ) );
		$this->assertSame( 0, $this->sent_count() );

		$alerts->alert( $this->processed( $this->make_finding( 'vuln.match', 'b', Severity::CRITICAL ) ) );
		$this->assertSame( 1, $this->sent_count() );
	}

	public function test_a_finding_alerts_only_once(): void {
		$row     = $this->make_finding( 'security.checksums', 'core', Severity::HIGH );
		$payload = $this->processed( $row );
		$alerts  = new Alerts();

		$this->assertTrue( $alerts->alert( $payload ) );
		$this->assertFalse( $alerts->alert( $payload ) );

		$this->assertSame( 1, $this->sent_count() );
	}

	public function test_findings_of_one_run_share_one_email(): void {
		$a = $this->make_finding( 'vuln.match', 'a', Severity::CRITICAL );
		$b = $this->make_finding( 'vuln.match', 'b', Severity::HIGH );

		( new Alerts() )->alert( $this->processed( $a, $b ) );

		$this->assertSame( 1, $this->sent_count() );
		$this->assertStringContainsString( '2 new alerts', $this->sent()->subject );
		$this->assertNotNull( $this->findings->find( (int) $b['id'] )['alerted_at'] );
	}

	public function test_accepted_findings_do_not_alert(): void {
		$row = $this->make_finding( 'errors.fatal', 'sig', Severity::CRITICAL );
		$this->findings->set_status( (int) $row['id'], 'accepted', 'known' );

		$this->assertFalse( ( new Alerts() )->alert( $this->processed( $row ) ) );
		$this->assertSame( 0, $this->sent_count() );
	}

	public function test_kill_switch_stops_alerts(): void {
		Settings::update( [ 'kill_switch' => true ] );

		$this->assertFalse( ( new Alerts() )->alert( $this->processed( $this->make_finding( 'errors.fatal' ) ) ) );
		$this->assertSame( 0, $this->sent_count() );
	}

	public function test_no_recipients_sends_nothing_and_keeps_the_finding_unalerted(): void {
		Settings::update( [ 'recipients' => [] ] );
		$row = $this->make_finding( 'errors.fatal' );

		$this->assertFalse( ( new Alerts() )->alert( $this->processed( $row ) ) );
		$this->assertNull( $this->findings->find( (int) $row['id'] )['alerted_at'] );
	}

	public function test_failed_send_leaves_the_finding_for_the_digest(): void {
		add_filter( 'pre_wp_mail', '__return_false' );
		$row = $this->make_finding( 'errors.fatal' );

		$this->assertFalse( ( new Alerts() )->alert( $this->processed( $row ) ) );
		$this->assertNull( $this->findings->find( (int) $row['id'] )['alerted_at'] );
	}

	public function test_alert_is_sent_again_when_a_finding_resolves_and_comes_back(): void {
		$check = new FakeCheck( 'errors.fatal', Result::pass( 'ok' ) );
		$this->register_checks( $check );
		$runner  = new Runner();
		$finding = static fn (): Result => Result::fail( 'bad', [], [ new Finding( 'sig-1', Severity::CRITICAL, 'Fatal in plugin', [] ) ] );

		$check->will_return( $finding );
		$runner->run( 'errors.fatal', 'cron' );
		$this->assertSame( 1, $this->sent_count(), 'first appearance alerts' );

		$runner->run( 'errors.fatal', 'cron' );
		$this->assertSame( 1, $this->sent_count(), 'still there: no second alert' );

		$check->will_return( Result::pass( 'fixed' ) );
		$runner->run( 'errors.fatal', 'cron' );
		$this->assertSame( 1, $this->sent_count(), 'resolving sends nothing' );

		$check->will_return( $finding );
		$runner->run( 'errors.fatal', 'cron' );
		$this->assertSame( 2, $this->sent_count(), 'back after resolving: new row, alerts again' );
	}

	public function test_only_ten_immediate_emails_per_hour_and_overflow_goes_to_the_digest(): void {
		$alerts = new Alerts();
		$rows   = [];

		for ( $i = 1; $i <= 12; $i++ ) {
			$rows[] = $this->make_finding( 'errors.fatal', 'sig' . $i, Severity::CRITICAL );
			$alerts->alert( $this->processed( $rows[ $i - 1 ] ) );
		}

		$this->assertSame( 10, $this->sent_count() );
		$this->assertNotNull( $this->findings->find( (int) $rows[9]['id'] )['alerted_at'] );
		$this->assertNull( $this->findings->find( (int) $rows[10]['id'] )['alerted_at'] );
		$this->assertNull( $this->findings->find( (int) $rows[11]['id'] )['alerted_at'] );

		// The next digest carries the two held-back alerts.
		$this->assertTrue( ( new Digest() )->run() );
		$this->assertSame( 11, $this->sent_count() );
		[ $held, $open ] = explode( 'STILL OPEN', $this->mime( 10 ) );
		$this->assertStringContainsString( 'HELD BACK BY THE HOURLY LIMIT (2)', $held );
		$this->assertStringContainsString( 'Title sig11', $held );
		$this->assertStringContainsString( 'Title sig12', $held );
		$this->assertStringNotContainsString( 'Title sig10', $held );
		$this->assertStringContainsString( 'Title sig10', $open, 'already alerted findings are only listed as still open' );
	}

	public function test_cap_is_configurable(): void {
		Settings::update( [ 'immediate_alerts_per_hour' => 1 ] );
		$alerts = new Alerts();

		$alerts->alert( $this->processed( $this->make_finding( 'errors.fatal', 'a' ) ) );
		$alerts->alert( $this->processed( $this->make_finding( 'errors.fatal', 'b' ) ) );

		$this->assertSame( 1, $this->sent_count() );
	}

	public function test_hook_is_registered_on_the_plugin_action(): void {
		$row = $this->make_finding( 'errors.fatal', 'via-hook' );

		do_action( 'amc_findings_processed', $this->processed( $row ) );

		$this->assertSame( 1, $this->sent_count() );
	}
}
