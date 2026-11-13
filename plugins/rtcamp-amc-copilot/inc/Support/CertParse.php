<?php
/**
 * Parsing helpers for TLS certificate data.
 *
 * Pure analysis of `openssl_x509_parse()` output: no network, no database,
 * no global state. The caller fetches the certificate itself and passes the
 * parsed array plus a reference time, so small "check" classes can feed this
 * from any transport.
 *
 * @package AMC\Support
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- The AMC prefix belongs to AMC Copilot, whose rename is carried on another branch.

namespace AMC\Support;

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals

use DateTimeImmutable;

/**
 * Class - CertParse
 */
final class CertParse {

	/**
	 * The number of days before expiry at which a certificate counts as
	 * "expiring soon".
	 *
	 * @var int
	 */
	private const EXPIRING_SOON_DAYS = 14;

	/**
	 * Seconds in a day.
	 *
	 * @var int
	 */
	private const SECONDS_PER_DAY = 86400;

	/**
	 * Disallow instantiation - this class only exposes static helpers.
	 */
	private function __construct() {}

	/**
	 * Summarises a parsed certificate against a reference time.
	 *
	 * @param array<string, mixed> $parsed_cert openssl_x509_parse() output.
	 * @param DateTimeImmutable    $now         Reference time.
	 *
	 * @return array{issuer: string, subject_cn: string, sans: string[], valid_from: DateTimeImmutable|null, valid_to: DateTimeImmutable|null, days_left: int, expired: bool, expiring_soon: bool}
	 *
	 * @throws \InvalidArgumentException When the validity timestamps are missing.
	 */
	public static function summary( array $parsed_cert, DateTimeImmutable $now ): array {
		$issuer_cn = self::dn_value( (array) ( $parsed_cert['issuer'] ?? [] ), [ 'CN', 'commonName' ] )
			?? self::dn_value( (array) ( $parsed_cert['issuer'] ?? [] ), [ 'O', 'organizationName' ] )
			?? '';

		$subject_cn = self::dn_value( (array) ( $parsed_cert['subject'] ?? [] ), [ 'CN', 'commonName' ] ) ?? '';

		$valid_from = self::cert_time( $parsed_cert, 'validFrom_time_t' );
		$valid_to   = self::cert_time( $parsed_cert, 'validTo_time_t' );

		if ( null === $valid_from || null === $valid_to ) {
			throw new \InvalidArgumentException( 'The parsed certificate is missing validFrom_time_t or validTo_time_t.' );
		}

		$now_ts    = $now->getTimestamp();
		$days_left = (int) floor( ( $valid_to->getTimestamp() - $now_ts ) / self::SECONDS_PER_DAY );
		$expired   = $now_ts >= $valid_to->getTimestamp();

		return [
			'issuer'        => $issuer_cn,
			'subject_cn'    => $subject_cn,
			'sans'          => self::parse_sans( (string) ( $parsed_cert['extensions']['subjectAltName'] ?? '' ) ),
			'valid_from'    => $valid_from,
			'valid_to'      => $valid_to,
			'days_left'     => $days_left,
			'expired'       => $expired,
			'expiring_soon' => ! $expired && $days_left <= self::EXPIRING_SOON_DAYS,
		];
	}

	/**
	 * Reads one relative-name attribute from an openssl subject/issuer map.
	 *
	 * `openssl_x509_parse()` values may be strings or arrays of strings.
	 *
	 * @param array<string, mixed> $dn        Subject or issuer map, e.g. `['CN' => 'example.com']`.
	 * @param string[]             $keys      Attribute keys to try, in order.
	 *
	 * @return string|null The first value found, or null.
	 */
	private static function dn_value( array $dn, array $keys ): ?string {
		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $dn ) ) {
				continue;
			}

			$value = $dn[ $key ];

			if ( is_array( $value ) ) {
				$value = $value[0] ?? null;
			}

			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return trim( (string) $value );
			}
		}

		return null;
	}

	/**
	 * Parses a subjectAltName extension string into its DNS and IP values.
	 *
	 * @param string $san_extension Raw extension value, e.g. `DNS:a, DNS:b, IP Address:1.2.3.4`.
	 *
	 * @return string[] SAN values without their prefixes, in listed order.
	 */
	private static function parse_sans( string $san_extension ): array {
		$sans = [];

		if ( '' === trim( $san_extension ) ) {
			return $sans;
		}

		foreach ( explode( ',', $san_extension ) as $entry ) {
			$entry = trim( $entry );

			if ( '' === $entry ) {
				continue;
			}

			$colon = strpos( $entry, ':' );

			if ( false === $colon ) {
				continue;
			}

			$prefix = strtolower( substr( $entry, 0, $colon ) );
			$value  = trim( substr( $entry, $colon + 1 ) );

			if ( in_array( $prefix, [ 'dns', 'ip address' ], true ) && '' !== $value ) {
				$sans[] = $value;
			}
		}

		return $sans;
	}

	/**
	 * Reads one of the certificate's Unix-timestamp fields into a date.
	 *
	 * @param array<string, mixed> $parsed_cert openssl_x509_parse() output.
	 * @param string               $key         Field name, e.g. `validTo_time_t`.
	 *
	 * @return DateTimeImmutable|null The timestamp in UTC, or null when absent.
	 */
	private static function cert_time( array $parsed_cert, string $key ): ?DateTimeImmutable {
		$timestamp = $parsed_cert[ $key ] ?? null;

		if ( ! is_int( $timestamp ) ) {
			return null;
		}

		return new DateTimeImmutable( '@' . $timestamp );
	}
}
