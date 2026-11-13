<?php
/**
 * Tests for the ssl.certificate check.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Checks\Security;

use AMC\Checks\Context;
use AMC\Checks\Group;
use AMC\Checks\Schedule;
use AMC\Checks\Security\SslCertificate;
use AMC\Checks\Severity;
use AMC\Checks\Status;
use AMC\Runner\ProcessedFindings;
use AMC\Runner\Runner;
use AMC\Storage\FindingsRepository;
use AMC\Tests\Support\ReplacesChecks;
use AMC\Tests\TestCase;

/**
 * Class SslCertificateTest
 */
final class SslCertificateTest extends TestCase {

	use ReplacesChecks;

	/**
	 * What the fake connection returns.
	 *
	 * @var array<string, mixed>
	 */
	private array $read = [];

	/**
	 * Reference time shared by the context and the certificates.
	 */
	private function now(): int {
		return (int) strtotime( '2026-11-14 12:00:00 UTC' );
	}

	/**
	 * Fake: the certificate is `days` days from expiry.
	 *
	 * @param int    $days   Days left.
	 * @param string $serial Serial number.
	 */
	private function cert_with( int $days, string $serial = '0A1B2C' ): void {
		$build      = $this->fixture( 'ssl-certificate/certs' );
		$this->read = [ 'parsed' => $build( $this->now(), $days, $serial ) ];
	}

	/**
	 * The check against the fake connection and an https site.
	 *
	 * @param string $url Site URL.
	 */
	private function check( string $url = 'https://example.test' ): SslCertificate {
		return new SslCertificate( fn (): array => $this->read, static fn (): string => $url );
	}

	/**
	 * Context at the reference time.
	 */
	private function ctx(): Context {
		return Context::for_tests( null, gmdate( 'Y-m-d H:i:s', $this->now() ) );
	}

	public function test_metadata(): void {
		$check = new SslCertificate();

		$this->assertSame( 'ssl.certificate', $check->id() );
		$this->assertSame( Group::SECURITY, $check->group() );
		$this->assertSame( Schedule::DAILY, $check->schedule() );
		$this->assertNotSame( '', $check->label() );
	}

	public function test_a_healthy_certificate_passes_with_details(): void {
		$this->cert_with( 60 );

		$result = $this->check()->run( $this->ctx() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( [], $result->findings );
		$this->assertTrue( $result->complete );
		$this->assertTrue( $result->data['available'] );
		$this->assertSame( 60, $result->data['days_left'] );
		$this->assertSame( 'R3', $result->data['issuer'] );
		$this->assertSame( '0A1B2C', $result->data['serial'] );
		$this->assertSame( [ 'example.test', 'www.example.test' ], $result->data['sans'] );
	}

	public function test_ten_days_left_raises_a_high_finding_with_days_left_in_its_data(): void {
		$this->cert_with( 10 );

		$result = $this->check()->run( $this->ctx() );

		$this->assertSame( Status::FAIL, $result->status );
		$this->assertCount( 1, $result->findings );

		$finding = $result->findings[0];
		$this->assertSame( 'ssl:0A1B2C', $finding->dedupe_key );
		$this->assertSame( Severity::HIGH, $finding->severity );
		$this->assertSame( 10, $finding->data['days_left'] );
		$this->assertIsInt( $finding->data['days_left'] );
		$this->assertStringContainsString( '10 days', $finding->title );
	}

	/**
	 * The 14-day boundary.
	 *
	 * @dataProvider provide_boundaries
	 *
	 * @param int       $days        Days left.
	 * @param bool      $has_finding Whether a finding is raised.
	 * @param ?Severity $severity Expected severity.
	 */
	public function test_threshold( int $days, bool $has_finding, ?Severity $severity ): void {
		$this->cert_with( $days );

		$result = $this->check()->run( $this->ctx() );

		$this->assertCount( $has_finding ? 1 : 0, $result->findings );
		if ( null !== $severity ) {
			$this->assertSame( $severity, $result->findings[0]->severity );
		}
	}

	/**
	 * Boundary cases.
	 *
	 * @return array<string, array{0: int, 1: bool, 2: ?Severity}>
	 */
	public static function provide_boundaries(): array {
		return [
			'15 days' => [ 15, false, null ],
			'14 days' => [ 14, true, Severity::HIGH ],
			'4 days'  => [ 4, true, Severity::HIGH ],
			'3 days'  => [ 3, true, Severity::CRITICAL ],
			'expired' => [ -2, true, Severity::CRITICAL ],
		];
	}

	public function test_an_expired_certificate_has_a_negative_days_left_and_the_expired_flag(): void {
		$this->cert_with( -5 );

		$result = $this->check()->run( $this->ctx() );

		$this->assertSame( Status::FAIL, $result->status );
		$this->assertTrue( $result->findings[0]->data['expired'] );
		$this->assertLessThan( 0, $result->findings[0]->data['days_left'] );
	}

	public function test_missing_openssl_or_blocked_outbound_is_unavailable_not_a_failure(): void {
		foreach ( [ 'openssl_missing', 'connect_failed', 'no_certificate' ] as $reason ) {
			$this->read = [
				'reason' => $reason,
				'error'  => 'Connection timed out',
			];

			$result = $this->check()->run( $this->ctx() );

			$this->assertSame( Status::INFO, $result->status, $reason );
			$this->assertFalse( $result->complete, 'Partial: an unreadable cert must not resolve findings' );
			$this->assertFalse( $result->data['available'] );
			$this->assertSame( $reason, $result->data['reason'] );
			$this->assertSame( [], $result->findings );
		}
	}

	public function test_unparseable_certificate_is_unavailable(): void {
		$this->read = [ 'parsed' => [ 'subject' => [ 'CN' => 'x' ] ] ];

		$result = $this->check()->run( $this->ctx() );

		$this->assertSame( Status::INFO, $result->status );
		$this->assertSame( 'unparseable', $result->data['reason'] );
	}

	public function test_a_site_without_https_is_unavailable(): void {
		$result = $this->check( 'http://example.test' )->run( $this->ctx() );

		$this->assertSame( Status::INFO, $result->status );
		$this->assertFalse( $result->data['https'] );
		$this->assertSame( 'not_https', $result->data['reason'] );
	}

	public function test_default_reader_reports_a_failed_connection(): void {
		$result = SslCertificate::fetch_certificate( 'invalid.invalid', 443 );

		$this->assertContains( $result['reason'], [ 'connect_failed', 'openssl_missing' ] );
	}

	/**
	 * Acceptance: a renewed certificate (new serial) resolves the finding.
	 */
	public function test_a_renewed_certificate_resolves_the_finding_and_alerts_once(): void {
		$processed = [];
		add_action(
			'amc_findings_processed',
			static function ( ProcessedFindings $p ) use ( &$processed ): void {
				$processed[] = $p;
			}
		);

		$this->replace_checks( $this->check() );
		$runner = new Runner();
		$repo   = new FindingsRepository();

		$this->cert_with( 10, 'AAA111' );
		$runner->run( 'ssl.certificate' );
		$this->assertCount( 1, $processed[0]->new );
		$this->assertSame( 10, $processed[0]->new[0]['data']['days_left'] );

		$runner->run( 'ssl.certificate' );
		$this->assertCount( 0, $processed[1]->new, 'Alert once' );

		// While the certificate cannot be read, the open finding stays open.
		$this->read = [ 'reason' => 'connect_failed' ];
		$runner->run( 'ssl.certificate' );
		$this->assertCount( 0, $processed[2]->resolved );
		$this->assertArrayHasKey( 'ssl:AAA111', $repo->unresolved_for_check( 'ssl.certificate' ) );

		// Renewed: a new serial and 90 days.
		$this->cert_with( 90, 'BBB222' );
		$runner->run( 'ssl.certificate' );
		$this->assertCount( 1, $processed[3]->resolved );
		$this->assertSame( 'ssl:AAA111', $processed[3]->resolved[0]['dedupe_key'] );
		$this->assertSame( [], $repo->unresolved_for_check( 'ssl.certificate' ) );
	}
}
