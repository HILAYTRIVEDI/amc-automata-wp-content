<?php
/**
 * What the plugin already knows about the site, for the setup wizard.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Admin;

use AMC\Checks\Context;
use AMC\Checks\Hosting\Platform;
use AMC\Core\Capabilities;
use AMC\Storage\RunsRepository;

/**
 * Class SetupDefaults
 *
 * Builds the `detected` part of `GET /amc/v1/setup`: facts the wizard shows
 * and `suggestions`, values in the `amc_saved_fields` shape
 * (`section => field => string`) that it uses for every Saved field that is
 * still empty. Nothing here is saved; the engineer confirms each step.
 *
 * Hosting comes from the latest `hosting.platform` result. On a fresh install
 * nothing has run yet, so the same check is run here, read-only and not
 * stored (it only reads constants, `$_SERVER` and the database version). SSL
 * needs a TLS connection, so it only comes from a stored `ssl.certificate` run.
 *
 * @since 0.1.0
 */
final class SetupDefaults {

	/**
	 * Hosting platform slug (HostDetect) => hosting type suggestion.
	 */
	public const HOST_LABELS = [
		'wpcom-vip' => 'WordPress VIP',
		'wpengine'  => 'WP Engine (managed WordPress)',
		'pantheon'  => 'Pantheon',
		'kinsta'    => 'Kinsta (managed WordPress)',
		'pressable' => 'Pressable (managed WordPress)',
	];

	/**
	 * Certificate issuers that only issue free certificates.
	 */
	public const FREE_ISSUERS = [ "Let's Encrypt", 'ZeroSSL' ];

	/**
	 * Constructor.
	 *
	 * @param RunsRepository|null $runs     Runs repository.
	 * @param callable|null       $platform Test seam: fn(): array, the hosting.platform data when no run is stored.
	 */
	public function __construct( private ?RunsRepository $runs = null, private $platform = null ) {
		$this->runs ??= new RunsRepository();
	}

	/**
	 * Detected facts and suggestions.
	 *
	 * @return array{
	 *   environment: string,
	 *   site_name: string,
	 *   home_url: string,
	 *   primary_domain: string,
	 *   admin_email: string,
	 *   engineer_emails: list<string>,
	 *   host: array{slug: string|null, label: string|null, web_server: string|null, php_version: string|null},
	 *   ssl: array{issuer: string|null, valid_to: string|null}|null,
	 *   suggestions: array<string, array<string, string>>
	 * }
	 */
	public function get(): array {
		$latest   = $this->runs->latest_per_check();
		$platform = isset( $latest['hosting.platform'] ) ? (array) $latest['hosting.platform']['data'] : $this->detect_platform();
		$ssl_data = isset( $latest['ssl.certificate'] ) ? (array) $latest['ssl.certificate']['data'] : null;

		$host_slug = self::string_or_null( $platform['host'] ?? null );
		$host      = [
			'slug'        => $host_slug,
			'label'       => null !== $host_slug ? ( self::HOST_LABELS[ $host_slug ] ?? $host_slug ) : null,
			'web_server'  => self::string_or_null( $platform['web_server'] ?? null ),
			'php_version' => self::string_or_null( $platform['php_version'] ?? null ),
		];

		$ssl = null;
		if ( null !== $ssl_data && ! empty( $ssl_data['available'] ) ) {
			$ssl = [
				'issuer'   => self::string_or_null( $ssl_data['issuer'] ?? null ),
				'valid_to' => self::string_or_null( $ssl_data['valid_to'] ?? null ),
			];
		}

		$domain    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$site_name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		return [
			'environment'     => wp_get_environment_type(),
			'site_name'       => $site_name,
			'home_url'        => home_url( '/' ),
			'primary_domain'  => $domain,
			'admin_email'     => (string) get_option( 'admin_email' ),
			'engineer_emails' => self::engineer_emails(),
			'host'            => $host,
			'ssl'             => $ssl,
			'suggestions'     => self::suggestions( $site_name, $domain, $host['label'], $ssl ),
		];
	}

	/**
	 * Saved field suggestions from the detected facts. Only fields with a
	 * value are included.
	 *
	 * @param string                                                 $site_name Site title.
	 * @param string                                                 $domain    Primary domain.
	 * @param string|null                                            $host      Hosting type label.
	 * @param array{issuer: string|null, valid_to: string|null}|null $ssl Certificate facts.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function suggestions( string $site_name, string $domain, ?string $host, ?array $ssl ): array {
		$suggestions = [
			'overview' => [ 'maintenance_schedule' => 'Monthly' ],
		];

		if ( '' !== $site_name ) {
			$suggestions['overview']['client_name'] = $site_name;
		}

		if ( '' !== $domain ) {
			$suggestions['cms']['websites_covered'] = $domain;
		}

		if ( null !== $host && isset( array_flip( self::HOST_LABELS )[ $host ] ) ) {
			$suggestions['hosting']['hosting_type'] = $host;
		}

		$issuer = $ssl['issuer'] ?? null;
		if ( null !== $issuer ) {
			foreach ( self::FREE_ISSUERS as $free ) {
				if ( false !== stripos( $issuer, $free ) ) {
					/* translators: %s: certificate issuer, e.g. Let's Encrypt. */
					$suggestions['ssl']['ssl_type'] = sprintf( __( 'Free (%s)', 'rtcamp-amc-copilot' ), $free );
					break;
				}
			}
		}

		return $suggestions;
	}

	/**
	 * Emails of every engineer (who gets alerts while the recipient list is empty).
	 *
	 * @return list<string>
	 */
	private static function engineer_emails(): array {
		$emails = [];

		foreach ( Capabilities::engineers() as $user ) {
			$emails[] = (string) $user->user_email;
		}

		return array_values( array_unique( $emails ) );
	}

	/**
	 * Run hosting.platform without storing it.
	 *
	 * @return array<string, mixed> The check's data.
	 */
	private function detect_platform(): array {
		if ( null !== $this->platform ) {
			return (array) ( $this->platform )();
		}

		return ( new Platform() )->run( new Context( get_current_blog_id(), 'manual' ) )->data;
	}

	/**
	 * A non-empty string, or null.
	 *
	 * @param mixed $value Value.
	 */
	private static function string_or_null( mixed $value ): ?string {
		return is_string( $value ) && '' !== $value ? $value : null;
	}
}
