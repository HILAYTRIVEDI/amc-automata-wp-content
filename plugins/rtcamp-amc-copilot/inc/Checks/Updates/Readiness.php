<?php
/**
 * Check: updates.readiness (WordPress major readiness).
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks\Updates;

use AMC\Checks\Check;
use AMC\Checks\Context;
use AMC\Checks\Finding;
use AMC\Checks\Group;
use AMC\Checks\Result;
use AMC\Checks\Schedule;
use AMC\Checks\Severity;
use AMC\Support\AdminFunctions;

/**
 * Class Readiness
 *
 * For every active plugin, compares its "Tested up to" value against the
 * next WordPress major (the branch after the installed version).
 * `get_plugins()` does not parse "Tested up to", so the value is read from
 * WordPress.org's update data for the installed version, then the plugin
 * file header, then the plugin's readme.txt (see tested_up_to()). Plugins
 * that have not declared a tested-up-to value, or are below the next
 * major, are reported so an engineer can confirm compatibility before a
 * major upgrade.
 *
 * Result::$data shape (consumed by the Updates screen and report CMS section):
 *   installed       string        Installed WordPress version.
 *   next_major      string        Next WordPress major (branch + 1).
 *   plugins         list<array{file: string, name: string, version: string,
 *                     tested_up_to: string|null, ready: bool}>
 *   ready           int           Active plugins tested up to the next major.
 *   not_ready       int           Active plugins below or missing tested-up-to.
 *
 * Status rules:
 *   all ready       -> PASS
 *   any not ready   -> WARN + Finding LOW per plugin
 *
 * Finding dedupe key: 'readiness:<file>:<tested_up_to>' so a plugin that
 * declares a new tested-up-to gets a fresh finding.
 *
 * @since 0.1.0
 */
final class Readiness implements Check {

	/**
	 * Returns the installed WordPress version.
	 *
	 * @var callable(): string
	 */
	private $version_source;

	/**
	 * Returns the installed plugins, keyed by file.
	 *
	 * @var callable(): array<string, array<string, mixed>>
	 */
	private $plugins_source;

	/**
	 * Returns the active plugin files.
	 *
	 * @var callable(): list<string>
	 */
	private $active_source;

	/**
	 * Returns a plugin's "Tested up to" value, null when not declared.
	 *
	 * @var callable(string): ?string
	 */
	private $tested_source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $version_source Test seam; default wp_get_wp_version().
	 * @param callable|null $plugins_source Test seam; default get_plugins().
	 * @param callable|null $active_source  Test seam; default get_option( 'active_plugins' ).
	 * @param callable|null $tested_source  Test seam; default self::tested_up_to().
	 */
	public function __construct(
		?callable $version_source = null,
		?callable $plugins_source = null,
		?callable $active_source = null,
		?callable $tested_source = null
	) {
		$this->version_source = $version_source ?? static fn (): string => function_exists( 'wp_get_wp_version' ) ? wp_get_wp_version() : '6.9.1';
		$this->plugins_source = $plugins_source ?? static fn (): array => AdminFunctions::ensure( 'get_plugins' ) ? get_plugins() : [];
		$this->active_source  = $active_source ?? static fn (): array => (array) get_option( 'active_plugins', [] );
		$this->tested_source  = $tested_source ?? static fn ( string $file ): ?string => self::tested_up_to( $file );
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'updates.readiness';
	}

	/**
	 * {@inheritDoc}
	 */
	public function group(): Group {
		return Group::UPDATES;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Major-upgrade readiness', 'rtcamp-amc-copilot' );
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
	 * @param Context $ctx Run context (unused here: readiness is host-wide).
	 */
	public function run( Context $ctx ): Result { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by the Check contract.
		$installed  = (string) ( $this->version_source )();
		$next_major = self::next_major( $installed );
		$active     = (array) ( $this->active_source )();
		$plugins    = (array) ( $this->plugins_source )();

		$rows      = [];
		$ready     = 0;
		$not_ready = 0;
		$findings  = [];

		foreach ( $active as $file ) {
			$meta     = is_array( $plugins[ $file ] ?? null ) ? $plugins[ $file ] : [];
			$tested   = trim( (string) ( $meta['Tested up to'] ?? '' ) );
			$tested   = '' !== $tested ? $tested : ( $this->tested_source )( (string) $file );
			$is_ready = null !== $tested && version_compare( $tested, $next_major, '>=' );

			if ( $is_ready ) {
				++$ready;
			} else {
				++$not_ready;

				$findings[] = new Finding(
					'readiness:' . (string) $file . ':' . ( $tested ?? 'none' ),
					Severity::LOW,
					sprintf(
						/* translators: 1: Plugin name, 2: Tested up to value, 3: Next WordPress major. */
						__( 'Plugin not yet tested against WordPress %3$s: %1$s (tested up to: %2$s).', 'rtcamp-amc-copilot' ),
						(string) ( $meta['Name'] ?? $file ),
						$tested ?? __( 'not declared', 'rtcamp-amc-copilot' ),
						$next_major
					),
					[
						'file'         => (string) $file,
						'tested_up_to' => $tested,
						'next_major'   => $next_major,
					]
				);
			}

			$rows[] = [
				'file'         => (string) $file,
				'name'         => (string) ( $meta['Name'] ?? $file ),
				'version'      => (string) ( $meta['Version'] ?? '' ),
				'tested_up_to' => $tested,
				'ready'        => $is_ready,
			];
		}

		$data = [
			'installed'  => $installed,
			'next_major' => $next_major,
			'plugins'    => $rows,
			'ready'      => $ready,
			'not_ready'  => $not_ready,
		];

		if ( [] === $findings ) {
			return Result::pass( __( 'All active plugins are tested against the next WordPress major.', 'rtcamp-amc-copilot' ), $data );
		}

		return Result::warn(
			sprintf(
				/* translators: %s: Number of plugins not ready. */
				_n(
					'%s active plugin is not yet tested against the next WordPress major.',
					'%s active plugins are not yet tested against the next WordPress major.',
					$not_ready,
					'rtcamp-amc-copilot'
				),
				number_format_i18n( $not_ready )
			),
			$data,
			$findings
		);
	}

	/**
	 * A plugin's "Tested up to" value, or null when it declares none.
	 *
	 * Order: WordPress.org's update data for the installed version (the
	 * `no_update` entry: a `response` entry describes the new version, not
	 * the installed one), then the plugin file header, then readme.txt.
	 *
	 * @param string $file Plugin file relative to the plugins directory.
	 */
	public static function tested_up_to( string $file ): ?string {
		$transient = get_site_transient( 'update_plugins' );
		$no_update = is_object( $transient ) && is_array( $transient->no_update ?? null ) ? $transient->no_update : [];
		$offer     = $no_update[ $file ] ?? null;
		$tested    = is_object( $offer ) || is_array( $offer ) ? trim( (string) ( ( (object) $offer )->tested ?? '' ) ) : '';

		if ( '' !== $tested ) {
			return $tested;
		}

		if ( ! defined( 'WP_PLUGIN_DIR' ) || '' === $file || str_contains( $file, '..' ) ) {
			return null;
		}

		$main   = WP_PLUGIN_DIR . '/' . $file;
		$readme = dirname( $main ) . '/readme.txt';

		foreach ( [ $main, $readme ] as $path ) {
			// The readme check must stay inside a plugin folder, never the plugins root.
			if ( $path === $readme && '.' === dirname( $file ) ) {
				continue;
			}

			if ( ! is_readable( $path ) ) {
				continue;
			}

			$headers = get_file_data( $path, [ 'tested' => 'Tested up to' ] );
			$tested  = trim( (string) ( $headers['tested'] ?? '' ) );

			if ( '' !== $tested ) {
				return $tested;
			}
		}

		return null;
	}

	/**
	 * Next WordPress major after the installed version's branch.
	 *
	 * @param string $version Installed version.
	 */
	private static function next_major( string $version ): string {
		$parts = explode( '.', $version );
		$major = (int) ( $parts[0] ?? 6 );
		$minor = (int) ( $parts[1] ?? 0 );

		if ( $minor >= 9 ) {
			return ( $major + 1 ) . '.0';
		}

		return $major . '.' . ( $minor + 1 );
	}
}
