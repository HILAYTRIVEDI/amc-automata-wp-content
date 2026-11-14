<?php
/**
 * Check: hosting.platform.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks\Hosting;

use AMC\Checks\Check;
use AMC\Checks\Context;
use AMC\Checks\Group;
use AMC\Checks\Result;
use AMC\Checks\Schedule;
use AMC\Support\HostDetect;

/**
 * Class Platform
 *
 * Detects the hosting platform and server software, plus PHP/DB version, OS,
 * memory limit and disk where readable.
 *
 * Result::$data shape:
 *   host        string|null Platform slug.
 *   web_server  string|null Web server slug.
 *   evidence    list<string>
 *   php_version string
 *   db_version  string|null
 *   os          string
 *   memory_limit string
 *   disk_total  int|null Bytes.
 *   disk_free   int|null Bytes.
 *
 * Status: PASS.
 *
 * @since 0.1.0
 */
final class Platform implements Check {

	/**
	 * Returns the constants map for HostDetect.
	 *
	 * @var callable(): array<string, mixed>
	 */
	private $constants_source;

	/**
	 * Returns the selected $_SERVER keys.
	 *
	 * @var callable(): array<string, mixed>
	 */
	private $server_source;

	/**
	 * Returns the DB version.
	 *
	 * @var callable(): ?string
	 */
	private $db_source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $constants_source Test seam; default collects defined constants.
	 * @param callable|null $server_source    Test seam; default selected $_SERVER keys.
	 * @param callable|null $db_source        Test seam; default $wpdb->db_version().
	 * @param callable|null $constant_reader  Test seam for the default collector: fn( string $name ): ?array{0: mixed}, null when undefined.
	 */
	public function __construct( ?callable $constants_source = null, ?callable $server_source = null, ?callable $db_source = null, ?callable $constant_reader = null ) {
		$reader                 = $constant_reader ?? static fn ( string $name ): ?array => defined( $name ) ? [ constant( $name ) ] : null;
		$this->constants_source = $constants_source ?? static function () use ( $reader ): array {
			$out = [];
			foreach ( HostDetect::constant_names() as $name ) {
				$found = $reader( $name );
				if ( null !== $found ) {
					$out[ $name ] = $found[0];
				}
			}

			return $out;
		};
		$this->server_source    = $server_source ?? static fn (): array => [ 'SERVER_SOFTWARE' => sanitize_text_field( (string) ( $_SERVER['SERVER_SOFTWARE'] ?? '' ) ) ];
		$this->db_source        = $db_source ?? static function (): ?string {
			global $wpdb;

			return method_exists( $wpdb, 'db_version' ) ? (string) $wpdb->db_version() : null;
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'hosting.platform';
	}

	/**
	 * {@inheritDoc}
	 */
	public function group(): Group {
		return Group::HOSTING;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Hosting platform', 'rtcamp-amc-copilot' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function schedule(): Schedule {
		return Schedule::WEEKLY;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Context $ctx Run context (unused here).
	 */
	public function run( Context $ctx ): Result { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by the Check contract.
		$detected = HostDetect::platform( (array) ( $this->constants_source )(), (array) ( $this->server_source )() );

		$disk_total = disk_total_space( ABSPATH );
		$disk_free  = disk_free_space( ABSPATH );

		return Result::pass(
			$detected['host'] ? sprintf( /* translators: %s: Host. */ __( 'Hosting platform: %s.', 'rtcamp-amc-copilot' ), $detected['host'] ) : __( 'Hosting platform not detected.', 'rtcamp-amc-copilot' ),
			[
				'host'         => $detected['host'],
				'web_server'   => $detected['web_server'],
				'evidence'     => $detected['evidence'],
				'php_version'  => PHP_VERSION,
				'db_version'   => ( $this->db_source )(),
				'os'           => PHP_OS,
				'memory_limit' => (string) ini_get( 'memory_limit' ),
				'disk_total'   => false === $disk_total ? null : (int) $disk_total,
				'disk_free'    => false === $disk_free ? null : (int) $disk_free,
			]
		);
	}
}
