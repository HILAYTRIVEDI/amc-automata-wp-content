<?php
/**
 * Tests for the CertParse helpers.
 *
 * @package AMC\Support
 */

declare( strict_types = 1 );

namespace AMC\Support\Tests;

use AMC\Support\CertParse;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 4 ) . '/inc/Support/CertParse.php';

/**
 * Class CertParseTest
 */
final class CertParseTest extends TestCase {

	/**
	 * Reference time for the fixtures: a fixed instant, no clock dependency.
	 */
	private function now(): DateTimeImmutable {
		return new DateTimeImmutable( '2026-11-13T12:00:00+00:00' );
	}

	/**
	 * A realistic openssl_x509_parse() array for a Let's Encrypt certificate.
	 *
	 * @param int|float $expires_in Days from the reference time to expiry (negative = past).
	 *
	 * @return array<string, mixed>
	 */
	private function cert( int|float $expires_in ): array {
		return [
			'name'             => '/CN=example.com',
			'subject'          => [
				'C'  => 'US',
				'CN' => 'example.com',
			],
			'issuer'           => [
				'C'  => 'US',
				'O'  => "Let's Encrypt",
				'CN' => 'R3',
			],
			'extensions'       => [
				'subjectAltName' => 'DNS:example.com, DNS:www.example.com, DNS:api.example.com, IP Address:93.184.216.34',
			],
			'validFrom_time_t' => $this->now()->modify( '-30 days' )->getTimestamp(),
			'validTo_time_t'   => $this->now()->modify( '+' . $expires_in . ' days' )->getTimestamp(),
		];
	}

	/**
	 * A healthy certificate is summarised correctly.
	 */
	public function test_summarises_a_healthy_certificate(): void {
		$result = CertParse::summary( $this->cert( 60 ), $this->now() );

		$this->assertSame( 'R3', $result['issuer'] );
		$this->assertSame( 'example.com', $result['subject_cn'] );
		$this->assertSame( [ 'example.com', 'www.example.com', 'api.example.com', '93.184.216.34' ], $result['sans'] );
		$this->assertSame( 60, $result['days_left'] );
		$this->assertFalse( $result['expired'] );
		$this->assertFalse( $result['expiring_soon'] );
		$this->assertSame( $this->now()->modify( '-30 days' )->getTimestamp(), $result['valid_from']->getTimestamp() );
		$this->assertSame( $this->now()->modify( '+60 days' )->getTimestamp(), $result['valid_to']->getTimestamp() );
	}

	/**
	 * A certificate close to expiry is flagged as expiring soon.
	 */
	public function test_flags_expiring_soon(): void {
		$result = CertParse::summary( $this->cert( 10 ), $this->now() );

		$this->assertSame( 10, $result['days_left'] );
		$this->assertFalse( $result['expired'] );
		$this->assertTrue( $result['expiring_soon'] );
	}

	/**
	 * Exactly 14 days is "soon"; 15 days is not.
	 *
	 * @dataProvider provide_expiry_boundaries
	 *
	 * @param int  $expires_in Days to expiry.
	 * @param bool $expected   Expected expiring_soon value.
	 */
	public function test_expiry_boundary( int $expires_in, bool $expected ): void {
		$this->assertSame( $expected, CertParse::summary( $this->cert( $expires_in ), $this->now() )['expiring_soon'] );
	}

	/**
	 * The "soon" boundary cases.
	 *
	 * @return array<string, array{0: int, 1: bool}>
	 */
	public static function provide_expiry_boundaries(): array {
		return [
			'14 days' => [ 14, true ],
			'15 days' => [ 15, false ],
		];
	}

	/**
	 * An expired certificate is expired, not "expiring soon".
	 */
	public function test_flags_an_expired_certificate(): void {
		$result = CertParse::summary( $this->cert( -2 ), $this->now() );

		$this->assertTrue( $result['expired'] );
		$this->assertFalse( $result['expiring_soon'] );
		$this->assertLessThan( 0, $result['days_left'] );
	}

	/**
	 * A certificate expiring within the day (0 whole days left) counts as
	 * expiring soon.
	 */
	public function test_expiring_today_counts_as_soon(): void {
		$cert                   = $this->cert( 60 );
		$cert['validTo_time_t'] = $this->now()->modify( '+12 hours' )->getTimestamp();

		$result = CertParse::summary( $cert, $this->now() );

		$this->assertSame( 0, $result['days_left'] );
		$this->assertFalse( $result['expired'] );
		$this->assertTrue( $result['expiring_soon'] );
	}

	/**
	 * Missing timestamps throw, since a summary without them is meaningless.
	 */
	public function test_missing_timestamps_throw(): void {
		$cert = $this->cert( 60 );
		$cert = array_filter(
			$cert,
			static function ( string $key ): bool {
				return 'validFrom_time_t' !== $key && 'validTo_time_t' !== $key;
			},
			ARRAY_FILTER_USE_KEY
		);

		$this->expectException( \InvalidArgumentException::class );

		CertParse::summary( $cert, $this->now() );
	}

	/**
	 * A SAN-less certificate yields an empty SAN list.
	 */
	public function test_handles_a_certificate_without_sans(): void {
		$cert               = $this->cert( 60 );
		$cert['extensions'] = [];

		$this->assertSame( [], CertParse::summary( $cert, $this->now() )['sans'] );
	}

	/**
	 * Issuer fallback: a certificate whose issuer has no CN falls back to O.
	 */
	public function test_issuer_falls_back_to_the_organisation(): void {
		$cert           = $this->cert( 60 );
		$cert['issuer'] = [
			'O' => 'Example CA',
			'C' => 'GB',
		];

		$this->assertSame( 'Example CA', CertParse::summary( $cert, $this->now() )['issuer'] );
	}
}
