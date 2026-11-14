<?php
/**
 * Tests for the email sent when a report is first published.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Reports;

use AMC\Core\KillSwitch;
use AMC\Core\Settings;
use AMC\Reports\ReportEmails;

/**
 * Class ReportPublishEmailTest
 */
final class ReportPublishEmailTest extends ReportsTestCase {

	/**
	 * Generated draft report.
	 *
	 * @var int
	 */
	private int $report = 0;

	/**
	 * {@inheritDoc}
	 */
	public function set_up(): void {
		parent::set_up();

		$this->report = (int) $this->generator()->generate( $this->october(), $this->engineer );

		// Generating sent the "draft ready" email; only count what publishing sends.
		reset_phpmailer_instance();
	}

	/**
	 * Publish the report.
	 */
	private function publish(): void {
		wp_update_post(
			[
				'ID'          => $this->report,
				'post_status' => 'publish',
			]
		);
	}

	/**
	 * Mails sent since set_up().
	 *
	 * @return list<array<string, mixed>>
	 */
	private function sent(): array {
		$mailer = tests_retrieve_phpmailer_instance();

		return $mailer ? array_values( $mailer->mock_sent ) : [];
	}

	/**
	 * Recipient of every mail sent, sorted.
	 *
	 * @return list<string>
	 */
	private function sent_to(): array {
		$to = [];
		foreach ( $this->sent() as $mail ) {
			$this->assertCount( 1, $mail['to'], 'one mail per recipient' );
			$to[] = $mail['to'][0][0];
		}
		sort( $to );

		return $to;
	}

	public function test_first_publish_emails_the_configured_list_and_client_admins(): void {
		Settings::update( [ 'report_publish_recipients' => [ 'pm@example.com', 'owner@example.org' ] ] );

		$this->publish();

		$expected = array_merge( [ 'owner@example.org', 'pm@example.com' ], ReportEmails::client_recipients() );
		sort( $expected );
		$this->assertContains( get_userdata( $this->client )->user_email, $expected );
		$this->assertSame( $expected, $this->sent_to() );
		$this->assertNotContains( get_userdata( $this->engineer )->user_email, $this->sent_to() );
		$this->assertNotEmpty( get_post_meta( $this->report, ReportEmails::SENT_META, true ) );
	}

	public function test_the_email_names_the_report_and_period_and_links_to_the_client_view(): void {
		Settings::update(
			[
				'report_publish_recipients' => [ 'pm@example.com' ],
				'notify_client_on_publish'  => false,
			]
		);

		$this->publish();

		$mail = $this->sent()[0];
		$this->assertSame( '[AMC][' . get_bloginfo( 'name' ) . '] New maintenance report: AMC-NWT-2026-10', $mail['subject'] );
		$this->assertStringContainsString( 'multipart/alternative', $mail['header'] );
		$this->assertStringContainsString( 'Content-Type: text/plain', $mail['body'] );
		$this->assertStringContainsString( 'Content-Type: text/html', $mail['body'] );
		$this->assertStringContainsString( 'Website Maintenance Report: October 2026', $mail['body'] );
		$this->assertStringContainsString( '2026-10-01 to 2026-10-31', $mail['body'] );
		$this->assertStringContainsString( 'page=amc-reports', $mail['body'] );
		$this->assertStringContainsString( 'report=' . $this->report, $mail['body'] );
	}

	public function test_the_configured_list_is_emailed_even_when_client_emails_are_off(): void {
		Settings::update(
			[
				'report_publish_recipients' => [ 'pm@example.com' ],
				'notify_client_on_publish'  => false,
			]
		);

		$this->publish();

		$this->assertSame( [ 'pm@example.com' ], $this->sent_to() );
	}

	public function test_an_empty_list_falls_back_to_client_admins(): void {
		$this->publish();

		$expected = ReportEmails::client_recipients();
		sort( $expected );
		$this->assertContains( get_userdata( $this->client )->user_email, $expected );
		$this->assertSame( $expected, $this->sent_to() );
	}

	public function test_an_address_on_both_lists_gets_one_mail(): void {
		Settings::update( [ 'report_publish_recipients' => [ strtoupper( get_userdata( $this->client )->user_email ) ] ] );

		$this->publish();

		$this->assertCount( count( ReportEmails::client_recipients() ), $this->sent(), 'no extra mail for the duplicate' );
	}

	public function test_nothing_is_sent_with_an_empty_list_and_client_emails_off(): void {
		Settings::update( [ 'notify_client_on_publish' => false ] );

		$this->publish();

		$this->assertSame( [], $this->sent() );
		$this->assertSame( '', get_post_meta( $this->report, ReportEmails::SENT_META, true ), 'not marked: nothing went out' );
	}

	public function test_updating_a_published_report_does_not_send_again(): void {
		Settings::update( [ 'report_publish_recipients' => [ 'pm@example.com' ] ] );
		$this->publish();
		$first = count( $this->sent() );

		wp_update_post(
			[
				'ID'           => $this->report,
				'post_content' => get_post_field( 'post_content', $this->report ) . "\n<!-- edited -->",
			]
		);

		$this->assertSame( $first, count( $this->sent() ) );
	}

	public function test_republishing_after_unpublishing_does_not_send_again(): void {
		Settings::update( [ 'report_publish_recipients' => [ 'pm@example.com' ] ] );
		$this->publish();
		$first = count( $this->sent() );

		wp_update_post(
			[
				'ID'          => $this->report,
				'post_status' => 'draft',
			]
		);
		$this->publish();

		$this->assertSame( $first, count( $this->sent() ) );
	}

	public function test_a_publish_that_sent_nothing_can_send_on_a_later_publish(): void {
		Settings::update(
			[
				'report_publish_recipients' => [ 'pm@example.com' ],
				'kill_switch'               => true,
			]
		);
		$this->assertTrue( KillSwitch::is_active() );
		$this->publish();
		$this->assertSame( [], $this->sent() );

		Settings::update( [ 'kill_switch' => false ] );
		wp_update_post(
			[
				'ID'          => $this->report,
				'post_status' => 'draft',
			]
		);
		$this->publish();

		$this->assertContains( 'pm@example.com', $this->sent_to() );
	}
}
