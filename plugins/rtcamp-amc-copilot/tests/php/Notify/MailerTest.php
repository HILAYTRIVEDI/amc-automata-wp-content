<?php
/**
 * Tests for Mailer and EmailRenderer.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Notify;

use AMC\Checks\Severity;
use AMC\Core\Capabilities;
use AMC\Core\Settings;
use AMC\Notify\EmailRenderer;
use AMC\Notify\Mailer;
use AMC\Reports\ReportPostType;

/**
 * Class MailerTest
 */
final class MailerTest extends NotifyTestCase {

	public function test_immediate_email_is_multipart_with_text_and_html(): void {
		$row = $this->make_finding( 'errors.fatal', 'sig', Severity::CRITICAL );

		Mailer::send_immediate( [ $row ] );

		$mime = $this->mime();
		$this->assertStringContainsString( 'multipart/alternative', $this->sent()->header );
		$this->assertStringContainsString( 'Content-Type: text/plain', $mime );
		$this->assertStringContainsString( 'Content-Type: text/html', $mime );
		$this->assertStringContainsString( '- [Critical] Title sig', $mime, 'plain-text part' );
		$this->assertStringContainsString( '<table', $mime, 'HTML part' );
		$this->assertStringContainsString( 'Title sig', $mime );
	}

	public function test_subject_starts_with_amc_and_site_name(): void {
		update_option( 'blogname', 'Acme &amp; Sons' );

		Mailer::send_immediate( [ $this->make_finding( 'errors.fatal', 'sig', Severity::CRITICAL ) ] );

		$this->assertStringStartsWith( '[AMC][Acme & Sons] ', $this->sent()->subject );
		$this->assertSame( '[AMC][Acme & Sons] Critical: Title sig', $this->sent()->subject );
	}

	public function test_email_links_to_the_console_finding(): void {
		$row = $this->make_finding( 'errors.fatal', 'sig', Severity::CRITICAL );

		Mailer::send_immediate( [ $row ] );

		$url = Mailer::finding_url( $row );
		$this->assertStringContainsString( 'page=amc-copilot', $url );
		$this->assertStringContainsString( 'finding=' . $row['id'], $url );
		$this->assertStringContainsString( 'finding=' . $row['id'], $this->mime() );
	}

	public function test_finding_url_is_filterable(): void {
		add_filter( 'amc_notify_finding_url', static fn (): string => 'https://console.test/f/1' );

		Mailer::send_immediate( [ $this->make_finding( 'errors.fatal' ) ] );

		$this->assertStringContainsString( 'https://console.test/f/1', $this->mime() );
	}

	public function test_recipients_come_from_settings(): void {
		Settings::update( [ 'recipients' => [ 'a@example.com', 'b@example.com' ] ] );

		Mailer::send_immediate( [ $this->make_finding( 'errors.fatal' ) ] );

		$to = array_map( static fn ( array $pair ): string => $pair[0], $this->sent()->to );
		$this->assertSame( [ 'a@example.com', 'b@example.com' ], $to );
	}

	public function test_recipients_default_to_engineers_when_the_list_is_empty(): void {
		Settings::update( [ 'recipients' => [] ] );
		$this->make_admin( 'eng@example.com', true );

		Mailer::send_immediate( [ $this->make_finding( 'errors.fatal' ) ] );

		$this->assertSame( 'eng@example.com', $this->sent()->to[0][0] );
	}

	public function test_html_is_escaped(): void {
		$row          = $this->make_finding( 'errors.fatal' );
		$row['title'] = '<script>alert(1)</script> & more';

		Mailer::send_immediate( [ $row ] );

		$this->assertStringNotContainsString( '<script>', $this->mime() );
		$this->assertStringContainsString( '&lt;script&gt;', $this->mime() );
	}

	public function test_plain_text_part_is_only_added_to_our_own_mail(): void {
		Mailer::send_immediate( [ $this->make_finding( 'errors.fatal' ) ] );
		$this->assertFalse( has_action( 'phpmailer_init', [ Mailer::class, 'attach_plain_text' ] ) );

		reset_phpmailer_instance();
		wp_mail( 'x@example.com', 'Other plugin mail', 'Hello' );

		$this->assertStringNotContainsString( 'multipart/alternative', $this->sent()->header );
	}

	public function test_nothing_is_sent_during_the_kill_switch_or_without_recipients(): void {
		Settings::update( [ 'kill_switch' => true ] );
		$this->assertFalse( Mailer::send( [ 'a@example.com' ], 'x', [ 'heading' => 'x' ] ) );

		Settings::update( [ 'kill_switch' => false ] );
		$this->assertFalse( Mailer::send( [], 'x', [ 'heading' => 'x' ] ) );
		$this->assertSame( 0, $this->sent_count() );
	}

	public function test_renderer_text_and_html_agree(): void {
		$parts = EmailRenderer::render(
			[
				'heading'      => 'Heading',
				'paragraphs'   => [ 'Para one' ],
				'action_url'   => 'https://example.com/go',
				'action_label' => 'Go',
				'sections'     => [
					[
						'title' => 'S',
						'items' => [
							[
								'title'    => 'Item',
								'severity' => 'High',
								'check'    => 'Check',
								'url'      => 'https://example.com/i',
							],
						],
						'more'  => 3,
					],
				],
			]
		);

		foreach ( [ 'Heading', 'Para one', 'https://example.com/go', 'Item', 'and 3 more' ] as $needle ) {
			$this->assertStringContainsString( $needle, $parts['text'] );
			$this->assertStringContainsString( $needle, $parts['html'] );
		}
		$this->assertStringNotContainsString( '<', $parts['text'] );
	}

	/**
	 * Create a report post.
	 *
	 * @param string $status Post status.
	 */
	private function report( string $status = 'draft' ): int {
		return self::factory()->post->create(
			[
				'post_type'   => ReportPostType::SLUG,
				'post_status' => $status,
				'post_title'  => 'October 2026',
			]
		);
	}

	public function test_report_draft_ready_goes_to_the_recipients_with_the_edit_link(): void {
		$id = $this->report();
		wp_set_current_user( $this->make_admin( 'x@example.com', true ) );

		$this->assertTrue( Mailer::send_report_draft_ready( $id ) );

		$this->assertSame( 'engineer@example.com', $this->sent()->to[0][0] );
		$this->assertSame( '[AMC][' . get_bloginfo( 'name' ) . '] Report draft ready: October 2026', $this->sent()->subject );
		$this->assertStringContainsString( 'post=' . $id, $this->mime() );
		$this->assertStringContainsString( 'multipart/alternative', $this->sent()->header );
	}

	public function test_report_published_goes_to_client_admins_only_one_mail_each(): void {
		// The activation hook (which gives administrators amc_view_reports) does not run in tests.
		get_role( 'administrator' )->add_cap( Capabilities::VIEW_REPORTS );
		$id = $this->report( 'publish' );
		$this->make_admin( 'client1@example.com' );
		$this->make_admin( 'client2@example.com' );
		$this->make_admin( 'engineer-admin@example.com', true );

		$this->assertTrue( Mailer::send_report_published( $id ) );

		$to = [];
		foreach ( tests_retrieve_phpmailer_instance()->mock_sent as $mail ) {
			$to[] = $mail['to'][0][0];
		}
		sort( $to );

		$this->assertSame( [ 'client1@example.com', 'client2@example.com' ], array_values( array_filter( $to, static fn ( string $a ): bool => str_starts_with( $a, 'client' ) ) ) );
		$this->assertNotContains( 'engineer-admin@example.com', $to );
		foreach ( tests_retrieve_phpmailer_instance()->mock_sent as $mail ) {
			$this->assertCount( 1, $mail['to'], 'clients never see each other' );
		}
		$this->assertStringContainsString( 'New maintenance report: October 2026', $this->sent()->subject );
	}

	public function test_report_published_respects_the_site_toggle(): void {
		$id = $this->report( 'publish' );
		reset_phpmailer_instance();
		$this->make_admin( 'client@example.com' );
		Settings::update( [ 'notify_client_on_publish' => false ] );

		$this->assertFalse( Mailer::send_report_published( $id ) );
		$this->assertSame( 0, $this->sent_count() );
	}

	public function test_report_published_is_on_by_default(): void {
		$this->assertTrue( (bool) Settings::get_value( 'notify_client_on_publish' ) );
	}

	public function test_report_content_comes_from_the_filter(): void {
		$id = $this->report();
		add_filter(
			'amc_report_email',
			static function ( array $content, string $event ): array {
				$content['subject']    = 'Custom ' . $event;
				$content['paragraphs'] = [ 'Written by D1' ];

				return $content;
			},
			10,
			2
		);

		Mailer::send_report_draft_ready( $id );

		$this->assertSame( '[AMC][' . get_bloginfo( 'name' ) . '] Custom draft_ready', $this->sent()->subject );
		$this->assertStringContainsString( 'Written by D1', $this->mime() );
	}

	public function test_report_emails_ignore_other_post_types(): void {
		$post = self::factory()->post->create();

		$this->assertFalse( Mailer::send_report_draft_ready( $post ) );
		$this->assertFalse( Mailer::send_report_published( 999999 ) );
		$this->assertSame( 0, $this->sent_count() );
	}

	public function test_report_published_uses_the_report_emails_content_and_list(): void {
		$id = $this->report( 'publish' );
		Settings::update(
			[
				'report_publish_recipients' => [ 'pm@example.com' ],
				'notify_client_on_publish'  => false,
			]
		);
		reset_phpmailer_instance();

		$this->assertTrue( Mailer::send_report_published( $id ) );

		$this->assertSame( 1, $this->sent_count() );
		$this->assertSame( 'pm@example.com', $this->sent()->to[0][0] );
		$this->assertStringContainsString( 'page=amc-reports', $this->mime(), 'links to the client view' );
		$this->assertStringContainsString( 'multipart/alternative', $this->sent()->header );
	}

	public function test_renderer_html_uses_the_rtcamp_brand(): void {
		$html = EmailRenderer::html(
			[
				'heading'    => 'Heading',
				'action_url' => 'https://example.com/go',
			]
		);

		$this->assertStringContainsString( 'Reddit Sans', $html );
		$this->assertStringContainsString( 'border-top:4px solid ' . \AMC\Core\Brand::RED, $html );
		$this->assertStringContainsString( 'background:' . \AMC\Core\Brand::INK, $html, 'the action button is ink' );
		$this->assertStringNotContainsString( '#2271b1', $html, 'no admin blue left' );
	}
}
