<?php
/**
 * Tests for the report email content.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Reports;

use AMC\Core\Capabilities;
use AMC\Core\Settings;
use AMC\Reports\Generator;
use AMC\Reports\ReportEmails;

/**
 * Class ReportEmailsTest
 */
final class ReportEmailsTest extends ReportsTestCase {

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
	}

	public function test_draft_ready_email_has_subject_links_and_both_parts(): void {
		$email = ReportEmails::draft_ready( $this->report );

		$this->assertStringStartsWith( '[AMC][' . get_bloginfo( 'name' ) . '] ', $email['subject'] );
		$this->assertStringContainsString( 'AMC-NWT-2026-10', $email['subject'] );
		$this->assertStringContainsString( Generator::edit_url( $this->report ), $email['text'] );
		$this->assertStringContainsString( 'post.php?post=' . $this->report . '&action=edit', $email['text'], 'built without a logged-in user, as in cron' );
		$this->assertStringContainsString( 'Website Maintenance Report: October 2026', $email['text'] );
		$this->assertStringContainsString( '<a href="' . esc_url( Generator::edit_url( $this->report ) ) . '">', $email['html'] );
		$this->assertStringNotContainsString( '<style', $email['html'], 'no custom styling' );
		$this->assertStringContainsString( '2026-10-01 to 2026-10-31', $email['text'] );
	}

	public function test_draft_ready_goes_to_the_engineers_on_the_recipient_list(): void {
		$this->assertContains( get_userdata( $this->engineer )->user_email, ReportEmails::draft_ready( $this->report )['recipients'], 'default: every amc_manage user' );

		Settings::update( [ 'recipients' => [ 'lead@example.test' ] ] );

		$this->assertSame( [ 'lead@example.test' ], ReportEmails::draft_ready( $this->report )['recipients'] );
	}

	public function test_published_email_goes_to_client_administrators_not_engineers(): void {
		$engineer_admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		Capabilities::grant( $engineer_admin );
		$no_view = self::factory()->user->create( [ 'role' => 'administrator' ] );
		get_userdata( $no_view )->add_cap( Capabilities::VIEW_REPORTS, false );

		$email = ReportEmails::published( $this->report );

		$this->assertContains( get_userdata( $this->client )->user_email, $email['recipients'] );
		$this->assertNotContains( get_userdata( $engineer_admin )->user_email, $email['recipients'], 'engineers published it' );
		$this->assertNotContains( get_userdata( $no_view )->user_email, $email['recipients'], 'no amc_view_reports' );
		$this->assertNotContains( get_userdata( $this->engineer )->user_email, $email['recipients'] );
		$this->assertStringContainsString( 'New maintenance report: AMC-NWT-2026-10', $email['subject'] );
		$this->assertStringContainsString( 'page=amc-reports&report=' . $this->report, $email['text'], 'links to the client view, not the editor' );
		$this->assertStringNotContainsString( 'post.php', $email['text'] );
	}

	public function test_published_email_respects_the_site_setting(): void {
		Settings::update( [ 'notify_client_on_publish' => false ] );

		$this->assertSame( [], ReportEmails::published( $this->report )['recipients'] );
	}

	public function test_the_content_can_be_filtered(): void {
		add_filter(
			'amc_report_draft_ready_email',
			static function ( array $email, int $post_id ): array {
				$email['subject'] .= ' #' . $post_id;

				return $email;
			},
			10,
			2
		);

		$this->assertStringEndsWith( '#' . $this->report, ReportEmails::draft_ready( $this->report )['subject'] );
	}

	public function test_the_content_is_escaped(): void {
		wp_update_post(
			[
				'ID'         => $this->report,
				'post_title' => '<script>alert(1)</script> Report',
			]
		);

		$email = ReportEmails::draft_ready( $this->report );

		$this->assertStringNotContainsString( '<script>', $email['html'] );
	}

	public function test_actions_are_listened_to_and_do_nothing_without_the_a2_mailer(): void {
		$this->assertNotFalse( has_action( 'amc_report_draft_ready' ) );
		$this->assertNotFalse( has_action( 'amc_report_published' ) );

		// Lane A2's Mailer may not be merged yet: firing the actions must not fail.
		do_action( 'amc_report_draft_ready', $this->report );
		do_action( 'amc_report_published', $this->report );

		$this->assertTrue( true );
	}
}
