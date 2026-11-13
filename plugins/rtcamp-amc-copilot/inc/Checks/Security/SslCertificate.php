<?php
/**
 * Check: ssl.certificate (peer certificate and expiry).
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks\Security;

use AMC\Checks\Check;
use AMC\Checks\Context;
use AMC\Checks\Finding;
use AMC\Checks\Group;
use AMC\Checks\Result;
use AMC\Checks\Schedule;
use AMC\Checks\Severity;
use AMC\Support\CertParse;

/**
 * Class SslCertificate
 *
 * Opens a TLS connection to the site's host with `capture_peer_cert`, parses
 * the certificate with openssl_x509_parse() and summarises it with CertParse.
 * Verification is switched off on purpose: an expired or mismatched
 * certificate must be READ, not refused.
 *
 * Unavailable is not a failure. No OpenSSL extension, a site that is not on
 * HTTPS, or a blocked outbound connection give INFO with `available: false`,
 * returned partial so an open expiry finding is not resolved by a run that saw
 * nothing.
 *
 * Result::$data shape:
 *   host        string
 *   https       bool         False when the site URL is not https.
 *   available   bool         False when the certificate could not be read.
 *   reason      string|null  openssl_missing | not_https | connect_failed | no_certificate | unparseable.
 *   error       string|null  Connection error text.
 *   issuer, subject_cn, serial: string; sans: list<string>
 *   valid_from, valid_to: string  UTC ISO 8601.
 *   days_left   int          Negative once expired.
 *   expired     bool
 *   expiring_soon bool       14 days or less left, not yet expired.
 *
 * Status rules: unavailable -> INFO; expired or 14 days or less left -> FAIL;
 * otherwise PASS.
 *
 * Finding (only when expired or 14 days or less are left): dedupe key
 * `ssl:<serial>`, so a renewed certificate (new serial) resolves it. Severity
 * CRITICAL when expired or 3 days or less are left, HIGH otherwise. Finding
 * data: `{ host, issuer, serial, valid_to, days_left, expired }`; Notify reads
 * `days_left`.
 *
 * @since 0.1.0
 */
final class SslCertificate implements Check {

	/**
	 * Connection timeout in seconds.
	 */
	private const TIMEOUT = 5;

	/**
	 * Certificate reader: callable( string $host, int $port ): array{parsed?: array, reason?: string, error?: string}.
	 *
	 * @var callable
	 */
	private $cert_source;

	/**
	 * Site URL: callable(): string.
	 *
	 * @var callable
	 */
	private $url_source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $cert_source Test seam; default stream_socket_client + openssl_x509_parse.
	 * @param callable|null $url_source  Test seam; default home_url().
	 */
	public function __construct( ?callable $cert_source = null, ?callable $url_source = null ) {
		$this->cert_source = $cert_source ?? [ self::class, 'fetch_certificate' ];
		$this->url_source  = $url_source ?? static fn (): string => home_url();
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'ssl.certificate';
	}

	/**
	 * {@inheritDoc}
	 */
	public function group(): Group {
		return Group::SECURITY;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'SSL certificate', 'rtcamp-amc-copilot' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function schedule(): Schedule {
		return Schedule::DAILY;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Context $ctx Run context.
	 */
	public function run( Context $ctx ): Result {
		$url   = (string) ( $this->url_source )();
		$host  = (string) wp_parse_url( $url, PHP_URL_HOST );
		$port  = (int) ( wp_parse_url( $url, PHP_URL_PORT ) ?? 443 );
		$https = 'https' === wp_parse_url( $url, PHP_URL_SCHEME );
		$data  = [
			'host'          => $host,
			'https'         => $https,
			'available'     => false,
			'reason'        => null,
			'error'         => null,
			'issuer'        => '',
			'subject_cn'    => '',
			'serial'        => '',
			'sans'          => [],
			'valid_from'    => null,
			'valid_to'      => null,
			'days_left'     => null,
			'expired'       => false,
			'expiring_soon' => false,
		];

		if ( ! $https ) {
			$data['reason'] = 'not_https';

			return Result::info( __( 'The site URL is not HTTPS, so there is no certificate to check.', 'rtcamp-amc-copilot' ), $data )->partial();
		}

		$read = ( $this->cert_source )( $host, $port );

		if ( ! isset( $read['parsed'] ) || ! is_array( $read['parsed'] ) ) {
			$data['reason'] = (string) ( $read['reason'] ?? 'connect_failed' );
			$data['error']  = isset( $read['error'] ) ? (string) $read['error'] : null;

			return Result::info( $this->unavailable_summary( $data['reason'] ), $data )->partial();
		}

		try {
			$summary = CertParse::summary( $read['parsed'], $ctx->now );
		} catch ( \InvalidArgumentException $e ) {
			$data['reason'] = 'unparseable';
			$data['error']  = $e->getMessage();

			return Result::info( $this->unavailable_summary( 'unparseable' ), $data )->partial();
		}

		$data = array_replace(
			$data,
			[
				'available'     => true,
				'issuer'        => $summary['issuer'],
				'subject_cn'    => $summary['subject_cn'],
				'serial'        => $summary['serial'],
				'sans'          => $summary['sans'],
				'valid_from'    => $summary['valid_from']?->format( 'c' ),
				'valid_to'      => $summary['valid_to']?->format( 'c' ),
				'days_left'     => $summary['days_left'],
				'expired'       => $summary['expired'],
				'expiring_soon' => $summary['expiring_soon'],
			]
		);

		if ( ! $summary['expired'] && ! $summary['expiring_soon'] ) {
			return Result::pass(
				sprintf(
					/* translators: 1: Days left, 2: Issuer. */
					__( 'The certificate is valid for %1$d more days (issuer: %2$s).', 'rtcamp-amc-copilot' ),
					$summary['days_left'],
					$summary['issuer']
				),
				$data
			);
		}

		$days  = $summary['days_left'];
		$title = $summary['expired']
			? sprintf(
				/* translators: %s: Host name. */
				__( 'The SSL certificate for %s has expired.', 'rtcamp-amc-copilot' ),
				$host
			)
			: sprintf(
				/* translators: 1: Host name, 2: Days left. */
				_n( 'The SSL certificate for %1$s expires in %2$d day.', 'The SSL certificate for %1$s expires in %2$d days.', $days, 'rtcamp-amc-copilot' ),
				$host,
				$days
			);

		$serial  = '' !== $summary['serial'] ? $summary['serial'] : md5( (string) $data['valid_to'] );
		$finding = new Finding(
			'ssl:' . $serial,
			$summary['expired'] || $days <= 3 ? Severity::CRITICAL : Severity::HIGH,
			$title,
			[
				'host'      => $host,
				'issuer'    => $summary['issuer'],
				'serial'    => $summary['serial'],
				'valid_to'  => $data['valid_to'],
				'days_left' => $days,
				'expired'   => $summary['expired'],
			]
		);

		return Result::fail( $title, $data, [ $finding ] );
	}

	/**
	 * Summary for an unavailable certificate.
	 *
	 * @param string $reason Reason code.
	 */
	private function unavailable_summary( string $reason ): string {
		return match ( $reason ) {
			'openssl_missing' => __( 'The SSL certificate could not be checked: the OpenSSL extension is not available.', 'rtcamp-amc-copilot' ),
			'no_certificate'  => __( 'The SSL certificate could not be checked: the server did not present one.', 'rtcamp-amc-copilot' ),
			'unparseable'     => __( 'The SSL certificate could not be checked: it could not be read.', 'rtcamp-amc-copilot' ),
			default           => __( 'The SSL certificate could not be checked: the server cannot make an outbound HTTPS connection to the site.', 'rtcamp-amc-copilot' ),
		};
	}

	/**
	 * Read the peer certificate.
	 *
	 * @param string $host Host.
	 * @param int    $port Port.
	 *
	 * @return array{parsed?: array<string, mixed>, reason?: string, error?: string}
	 */
	public static function fetch_certificate( string $host, int $port ): array {
		if ( ! function_exists( 'openssl_x509_parse' ) || ! function_exists( 'stream_socket_client' ) ) {
			return [ 'reason' => 'openssl_missing' ];
		}

		$context = stream_context_create(
			[
				'ssl' => [
					'capture_peer_cert' => true,
					'verify_peer'       => false,
					'verify_peer_name'  => false,
					'SNI_enabled'       => true,
					'peer_name'         => $host,
				],
			]
		);

		$errno  = 0;
		$errstr = '';
		$client = @stream_socket_client( // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed connection is reported through $errstr.
			'ssl://' . $host . ':' . $port,
			$errno,
			$errstr,
			self::TIMEOUT,
			STREAM_CLIENT_CONNECT,
			$context
		);

		if ( false === $client ) {
			return [
				'reason' => 'connect_failed',
				'error'  => trim( $errstr ) ?: 'Connection failed (' . $errno . ').',
			];
		}

		$params = stream_context_get_params( $client );
		fclose( $client ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- A network socket, not a file.

		$cert = $params['options']['ssl']['peer_certificate'] ?? null;
		if ( null === $cert ) {
			return [ 'reason' => 'no_certificate' ];
		}

		$parsed = openssl_x509_parse( $cert );

		return false === $parsed ? [ 'reason' => 'unparseable' ] : [ 'parsed' => $parsed ];
	}
}
