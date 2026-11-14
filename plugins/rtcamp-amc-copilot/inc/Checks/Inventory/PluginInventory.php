<?php
/**
 * Check: inventory.plugins.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks\Inventory;

use AMC\Capture\DeployDiff;
use AMC\Checks\Check;
use AMC\Checks\Context;
use AMC\Checks\Finding;
use AMC\Checks\Group;
use AMC\Checks\Result;
use AMC\Checks\Schedule;
use AMC\Checks\Severity;
use AMC\Storage\SnapshotsRepository;
use AMC\Support\AdminFunctions;

/**
 * Class PluginInventory
 *
 * Inventory of installed plugins: active and network-active lists, on wp.org or
 * not, closed on wp.org, last updated on wp.org. Raises a finding when a plugin
 * is closed or abandoned for over 2 years. Stores a weekly snapshot in
 * `amc_snapshots` (kind `plugins`) and diffs it against the previous one; plugin
 * installs, version changes and removals found that way are logged to
 * `amc_changes` with source `deploy` (via DeployDiff, which skips anything the
 * upgrader hooks already logged, so wp-admin and WP-CLI updates are not repeated).
 *
 * Result::$data shape (consumed by the Inventory screen and report CMS section):
 *   plugins list<array{file, name, version, active, network_active, wp_org, closed, last_updated, abandoned}>
 *     (a closed plugin is wp_org true and closed true; last_updated is null for it)
 *   total int, active int, network_active int, closed int, abandoned int
 *   changes array{added, removed, updated} since the previous snapshot
 *
 * Status rules:
 *   any closed or abandoned -> WARN + Finding MEDIUM per plugin
 *   otherwise                -> PASS
 *
 * @since 0.1.0
 */
final class PluginInventory implements Check {

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
	 * Returns the network-active plugin files.
	 *
	 * @var callable(): list<string>
	 */
	private $network_source;

	/**
	 * Returns wp.org plugin information for a slug: an object, a WP_Error (the
	 * message is "closed" for a closed plugin), or false when unavailable.
	 *
	 * @var callable(string): mixed
	 */
	private $api_source;

	/**
	 * Snapshots repository used to store and diff the weekly snapshot.
	 *
	 * @var SnapshotsRepository
	 */
	private $snapshots;

	/**
	 * Constructor.
	 *
	 * @param callable|null            $plugins_source Test seam; default get_plugins().
	 * @param callable|null            $active_source  Test seam; default get_option( 'active_plugins' ).
	 * @param callable|null            $network_source Test seam; default keys of get_site_option( 'active_sitewide_plugins' ).
	 * @param callable|null            $api_source     Test seam; default plugins_api().
	 * @param SnapshotsRepository|null $snapshots      Test seam; default new SnapshotsRepository().
	 */
	public function __construct(
		?callable $plugins_source = null,
		?callable $active_source = null,
		?callable $network_source = null,
		?callable $api_source = null,
		?SnapshotsRepository $snapshots = null
	) {
		$this->plugins_source = $plugins_source ?? static fn (): array => AdminFunctions::ensure( 'get_plugins' ) ? get_plugins() : [];
		$this->active_source  = $active_source ?? static fn (): array => (array) get_option( 'active_plugins', [] );
		$this->network_source = $network_source ?? static fn (): array => array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) );
		$this->api_source     = $api_source ?? static function ( string $slug ): mixed {
			if ( ! AdminFunctions::ensure( 'plugins_api' ) ) {
				return false;
			}

			return plugins_api( 'plugin_information', [ 'slug' => $slug ] );
		};
		$this->snapshots      = $snapshots ?? new SnapshotsRepository();
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'inventory.plugins';
	}

	/**
	 * {@inheritDoc}
	 */
	public function group(): Group {
		return Group::INVENTORY;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Plugin inventory', 'rtcamp-amc-copilot' );
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
	 * @param Context $ctx Run context.
	 */
	public function run( Context $ctx ): Result {
		$plugins   = (array) ( $this->plugins_source )();
		$active    = (array) ( $this->active_source )();
		$networked = (array) ( $this->network_source )();

		$rows      = [];
		$findings  = [];
		$closed    = 0;
		$abandoned = 0;

		foreach ( $plugins as $file => $meta ) {
			$meta          = is_array( $meta ) ? $meta : [];
			$slug          = self::slug( (string) $file );
			$info          = ( $this->api_source )( $slug );
			$wp_org        = false;
			$closed_plugin = false;
			$last_updated  = null;

			if ( is_wp_error( $info ) ) {
				// A closed plugin is still on wp.org: the API answers {"error":"closed"} and
				// plugins_api() turns that into a WP_Error whose message is "closed". Any
				// other error ("Plugin not found.", a network failure) tells us nothing.
				if ( 'closed' === $info->get_error_message() ) {
					$wp_org        = true;
					$closed_plugin = true;
				}
			} elseif ( is_object( $info ) ) {
				$wp_org       = true;
				$last_updated = isset( $info->last_updated ) && is_string( $info->last_updated ) ? $info->last_updated : null;
			}

			$abandoned_plugin = false;
			if ( $wp_org && null !== $last_updated ) {
				$stamp = strtotime( $last_updated );
				if ( false !== $stamp && $stamp < strtotime( '-2 years', $ctx->now->getTimestamp() ) ) {
					$abandoned_plugin = true;
				}
			}

			if ( $closed_plugin ) {
				++$closed;
				$findings[] = new Finding(
					'plugin:' . (string) $file . ':closed',
					Severity::MEDIUM,
					sprintf(
						/* translators: %s: Plugin name. */
						__( 'Plugin closed on wordpress.org: %s.', 'rtcamp-amc-copilot' ),
						(string) ( $meta['Name'] ?? $file )
					),
					[ 'file' => (string) $file ]
				);
			}

			if ( $abandoned_plugin ) {
				++$abandoned;
				$findings[] = new Finding(
					'plugin:' . (string) $file . ':abandoned',
					Severity::MEDIUM,
					sprintf(
						/* translators: %s: Plugin name. */
						__( 'Plugin abandoned (no update in over 2 years): %s.', 'rtcamp-amc-copilot' ),
						(string) ( $meta['Name'] ?? $file )
					),
					[
						'file'         => (string) $file,
						'last_updated' => $last_updated,
					]
				);
			}

			$rows[] = [
				'file'           => (string) $file,
				'name'           => (string) ( $meta['Name'] ?? '' ),
				'version'        => (string) ( $meta['Version'] ?? '' ),
				'active'         => in_array( (string) $file, $active, true ),
				'network_active' => in_array( (string) $file, $networked, true ),
				'wp_org'         => $wp_org,
				'closed'         => $closed_plugin,
				'last_updated'   => $last_updated,
				'abandoned'      => $abandoned_plugin,
			];
		}

		// Diff against the previous snapshot.
		$previous = $this->snapshots->latest( 'plugins' );
		$changes  = [
			'added'   => [],
			'removed' => [],
			'updated' => [],
		];

		$rows_by_file = [];
		foreach ( $rows as $row ) {
			$rows_by_file[ $row['file'] ] = $row;
		}

		if ( is_array( $previous ) && isset( $previous['data']['plugins'] ) && is_array( $previous['data']['plugins'] ) ) {
			$previous_map = [];
			foreach ( $previous['data']['plugins'] as $row ) {
				if ( is_array( $row ) && isset( $row['file'] ) ) {
					$previous_map[ (string) $row['file'] ] = $row;
				}
			}

			foreach ( $rows as $row ) {
				if ( ! isset( $previous_map[ $row['file'] ] ) ) {
					$changes['added'][] = $row['file'];
				} elseif ( (string) ( $previous_map[ $row['file'] ]['version'] ?? '' ) !== $row['version'] ) {
					$changes['updated'][] = $row['file'];
				}
			}

			foreach ( array_keys( $previous_map ) as $file ) {
				if ( ! isset( $rows_by_file[ $file ] ) ) {
					$changes['removed'][] = $file;
				}
			}

			// Log the differences to amc_changes (source `deploy`). Changes that
			// ChangeLog's upgrader hooks already logged since the previous
			// snapshot are skipped by DeployDiff. Without a usable previous
			// snapshot there is no baseline, so nothing is logged.
			DeployDiff::record(
				'plugin',
				self::snapshot_map( array_values( $previous_map ) ),
				self::snapshot_map( $rows ),
				is_string( $previous['taken_at'] ?? null ) ? $previous['taken_at'] : null
			);
		}

		// Store the new snapshot.
		$this->snapshots->insert( 'plugins', [ 'plugins' => $rows ] );

		$data = [
			'plugins'        => $rows,
			'total'          => count( $rows ),
			'active'         => count( $active ),
			'network_active' => count( $networked ),
			'closed'         => $closed,
			'abandoned'      => $abandoned,
			'changes'        => $changes,
		];

		if ( [] !== $findings ) {
			return Result::warn(
				sprintf(
					/* translators: %s: Number of closed or abandoned plugins. */
					_n(
						'%s plugin is closed or abandoned.',
						'%s plugins are closed or abandoned.',
						count( $findings ),
						'rtcamp-amc-copilot'
					),
					number_format_i18n( count( $findings ) )
				),
				$data,
				$findings
			);
		}

		return Result::pass( __( 'No closed or abandoned plugins found.', 'rtcamp-amc-copilot' ), $data );
	}

	/**
	 * Maps snapshot rows to the file => { name, version } shape DeployDiff expects.
	 *
	 * @param list<array<string, mixed>> $rows Snapshot rows.
	 *
	 * @return array<string, array{name: string, version: string}>
	 */
	private static function snapshot_map( array $rows ): array {
		$map = [];

		foreach ( $rows as $row ) {
			$map[ (string) $row['file'] ] = [
				'name'    => (string) ( $row['name'] ?? '' ),
				'version' => (string) ( $row['version'] ?? '' ),
			];
		}

		return $map;
	}

	/**
	 * Extracts a slug from a plugin file path.
	 *
	 * @param string $file Plugin file.
	 */
	private static function slug( string $file ): string {
		$parts = explode( '/', $file );

		return (string) ( $parts[0] ?? $file );
	}
}
