<?php
/**
 * Tests for ReportIssues.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Reports;

use AMC\Reports\ReportIssues;
use AMC\Reports\ReportPostType;
use AMC\Reports\SnapshotSchema;
use AMC\Tests\TestCase;

/**
 * Class ReportIssuesTest
 */
final class ReportIssuesTest extends TestCase {

	public function test_detect_returns_empty_when_no_issues(): void {
		$snapshot = SnapshotSchema::empty_snapshot();
		$issues   = ReportIssues::detect( $snapshot );

		$this->assertSame( [], $issues );
		$this->assertFalse( ReportIssues::has_failures( $snapshot ) );
	}

	public function test_detect_security_vulnerabilities(): void {
		$snapshot = SnapshotSchema::empty_snapshot();
		$snapshot['sections']['security']['vulnerabilities'] = [
			[
				'component'   => 'vulnerable-plugin',
				'reference'   => 'CVE-2024-1234',
				'severity'    => 'critical',
				'status'      => 'open',
				'fixed_in'    => '2.0.0',
				'first_seen'  => '2026-10-01',
				'resolved_at' => null,
			],
		];

		$issues = ReportIssues::detect( $snapshot );

		$this->assertCount( 1, $issues );
		$this->assertSame( 'security', $issues[0]['section'] );
		$this->assertSame( 'amc/section-security', $issues[0]['block'] );
		$this->assertSame( 'fail', $issues[0]['severity'] );
		$this->assertStringContainsString( 'vulnerable-plugin', $issues[0]['title'] );
		$this->assertStringContainsString( 'page=amc-copilot&screen=vulnerabilities', $issues[0]['url'] );
		$this->assertTrue( ReportIssues::has_failures( $snapshot ) );
	}

	public function test_detect_core_checksums_modified(): void {
		$snapshot = SnapshotSchema::empty_snapshot();
		$snapshot['sections']['security']['core_checksums'] = [
			'status'         => 'fail',
			'modified_files' => [ 'wp-includes/version.php' ],
		];

		$issues = ReportIssues::detect( $snapshot );

		$this->assertCount( 1, $issues );
		$this->assertSame( 'security', $issues[0]['section'] );
		$this->assertSame( 'fail', $issues[0]['severity'] );
		$this->assertStringContainsString( 'Modified WordPress Core Files', $issues[0]['title'] );
	}

	public function test_detect_php_fatal_errors(): void {
		$snapshot = SnapshotSchema::empty_snapshot();
		$snapshot['sections']['php_errors']['fatal_count'] = 3;

		$issues = ReportIssues::detect( $snapshot );

		$this->assertCount( 1, $issues );
		$this->assertSame( 'php_errors', $issues[0]['section'] );
		$this->assertSame( 'amc/section-php-errors', $issues[0]['block'] );
		$this->assertSame( 'fail', $issues[0]['severity'] );
		$this->assertStringContainsString( '3', $issues[0]['message'] );
	}

	public function test_detect_smoke_check_failure(): void {
		$snapshot = SnapshotSchema::empty_snapshot();

		$snapshot['sections']['smoke_checks']['urls'] = [
			[
				'url'             => 'https://example.com/checkout',
				'runs'            => 10,
				'pass_rate'       => 80.0,
				'avg_response_ms' => 350,
			],
		];

		$issues = ReportIssues::detect( $snapshot );

		$this->assertCount( 1, $issues );
		$this->assertSame( 'smoke_checks', $issues[0]['section'] );
		$this->assertSame( 'amc/section-smoke-checks', $issues[0]['block'] );
		$this->assertSame( 'fail', $issues[0]['severity'] );
		$this->assertStringContainsString( 'https://example.com/checkout', $issues[0]['title'] );
	}

	public function test_detect_ssl_expired(): void {
		$snapshot = SnapshotSchema::empty_snapshot();

		$snapshot['sections']['ssl'] = [
			'https'      => true,
			'days_left'  => -2,
			'expires_at' => '2026-10-10',
			'issuer'     => 'Let\'s Encrypt',
		];

		$issues = ReportIssues::detect( $snapshot );

		$this->assertCount( 1, $issues );
		$this->assertSame( 'ssl', $issues[0]['section'] );
		$this->assertSame( 'amc/section-ssl', $issues[0]['block'] );
		$this->assertSame( 'fail', $issues[0]['severity'] );
		$this->assertStringContainsString( 'Expired', $issues[0]['title'] );
	}

	public function test_update_post_issues(): void {
		$post_id = self::factory()->post->create( [ 'post_type' => ReportPostType::SLUG ] );

		$snapshot = SnapshotSchema::empty_snapshot();
		$snapshot['sections']['php_errors']['fatal_count'] = 1;

		ReportIssues::update_post_issues( $post_id, $snapshot );

		$this->assertSame( '1', get_post_meta( $post_id, '_amc_has_failures', true ) );
		$this->assertSame( '1', get_post_meta( $post_id, '_amc_issue_count', true ) );

		$issues = ReportIssues::for_post( $post_id );
		$this->assertCount( 1, $issues );
		$this->assertSame( 'php_errors', $issues[0]['section'] );
	}
}
