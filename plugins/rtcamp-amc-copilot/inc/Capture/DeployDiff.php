<?php
/**
 * Deploy diff: version changes that no WordPress hook logged.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Capture;

use AMC\Storage\ChangesRepository;

/**
 * Class DeployDiff
 *
 * Code that arrives through a deploy (git, rsync, a container image) never
 * fires `upgrader_process_complete`, so ChangeLog does not see it. The
 * `inventory.plugins` / `inventory.themes` checks (lane B1) call
 * DeployDiff::record() with the previous and the current snapshot; it writes
 * `amc_changes` rows with source `deploy` for what changed and was not logged.
 *
 * Usage from a check:
 *
 *   $previous = ( new SnapshotsRepository() )->latest( 'plugins' );
 *   // ... build $current, store it with SnapshotsRepository::insert() ...
 *   DeployDiff::record( 'plugin', $previous['data'] ?? null, $current, $previous['taken_at'] ?? null );
 *
 * Snapshot shape (both arguments): a map keyed by plugin file or slug (plugins),
 * stylesheet (themes) or `wordpress` (core), whose value is either the version
 * string or `[ 'name' => string, 'version' => string ]` (`Name` / `Version`
 * work too).
 *
 * Rules:
 *
 * - `$previous === null` (no earlier snapshot) writes nothing: there is no baseline.
 * - In current only: `install`. In both with a different version: `update`.
 *   In previous only: `delete`.
 * - An item is skipped when a row with the same type, item, action and new
 *   version already exists since `$since` (a hook logged it, or an earlier call
 *   did), so calling it twice does not duplicate rows.
 * - `$since` is the previous snapshot's `taken_at` (UTC `Y-m-d H:i:s`); the
 *   default is the last two days.
 *
 * @since 0.1.0
 */
final class DeployDiff {

	/**
	 * Look-back for "already logged" when the caller gives no `$since`.
	 */
	public const DEFAULT_WINDOW = 2 * DAY_IN_SECONDS;

	/**
	 * Write `deploy` rows for the difference between two snapshots.
	 *
	 * @param string                    $type     plugin | theme | core.
	 * @param array<string, mixed>|null $previous Previous snapshot, or null when there is none.
	 * @param array<string, mixed>      $current  Current snapshot.
	 * @param string|null               $since    UTC datetime of the previous snapshot.
	 * @param int|null                  $blog_id  Site (default: current).
	 * @param ChangesRepository|null    $changes  Repository (test seam).
	 *
	 * @return int Rows written.
	 */
	public static function record( string $type, ?array $previous, array $current, ?string $since = null, ?int $blog_id = null, ?ChangesRepository $changes = null ): int {
		if ( null === $previous || ! in_array( $type, [ 'plugin', 'theme', 'core' ], true ) ) {
			return 0;
		}

		$changes ??= new ChangesRepository();
		$since   ??= gmdate( 'Y-m-d H:i:s', time() - self::DEFAULT_WINDOW );
		$old       = self::normalise( $type, $previous );
		$new       = self::normalise( $type, $current );
		$written   = 0;

		foreach ( $new as $item => $entry ) {
			if ( ! isset( $old[ $item ] ) ) {
				$written += self::write( $changes, $type, 'install', $item, $entry['name'], '', $entry['version'], $since, $blog_id );
			} elseif ( $old[ $item ]['version'] !== $entry['version'] ) {
				$written += self::write( $changes, $type, 'update', $item, $entry['name'], $old[ $item ]['version'], $entry['version'], $since, $blog_id );
			}
		}

		foreach ( $old as $item => $entry ) {
			if ( ! isset( $new[ $item ] ) ) {
				$written += self::write( $changes, $type, 'delete', $item, $entry['name'], $entry['version'], '', $since, $blog_id );
			}
		}

		return $written;
	}

	/**
	 * Normalise a snapshot to item => { name, version }.
	 *
	 * @param string               $type     plugin | theme | core.
	 * @param array<string, mixed> $snapshot Snapshot.
	 *
	 * @return array<string, array{name: string, version: string}>
	 */
	private static function normalise( string $type, array $snapshot ): array {
		$out = [];

		foreach ( $snapshot as $key => $value ) {
			$key  = (string) $key;
			$item = 'plugin' === $type ? ChangeLog::plugin_slug( $key ) : $key;

			if ( is_array( $value ) ) {
				$version = (string) ( $value['version'] ?? $value['Version'] ?? '' );
				$name    = (string) ( $value['name'] ?? $value['Name'] ?? $item );
			} else {
				$version = (string) $value;
				$name    = $item;
			}

			$out[ $item ] = [
				'name'    => $name,
				'version' => $version,
			];
		}

		return $out;
	}

	/**
	 * Insert one row unless an equivalent one is already logged.
	 *
	 * @param ChangesRepository $changes     Repository.
	 * @param string            $type        Type.
	 * @param string            $action      install | update | delete.
	 * @param string            $item        Item.
	 * @param string            $name        Display name.
	 * @param string            $old_version Old version.
	 * @param string            $new_version New version.
	 * @param string            $since       UTC datetime.
	 * @param int|null          $blog_id     Site.
	 *
	 * @return int 1 when a row was written.
	 */
	private static function write( ChangesRepository $changes, string $type, string $action, string $item, string $name, string $old_version, string $new_version, string $since, ?int $blog_id ): int {
		$logged = $changes->query(
			[
				'blog_id'  => $blog_id,
				'type'     => $type,
				'item'     => $item,
				'action'   => $action,
				'since'    => $since,
				'per_page' => 100,
			]
		)['items'];

		foreach ( $logged as $row ) {
			if ( (string) $row['new_version'] === $new_version ) {
				return 0;
			}
		}

		$changes->insert(
			[
				'blog_id'     => $blog_id,
				'type'        => $type,
				'action'      => $action,
				'item'        => $item,
				'item_name'   => $name,
				'old_version' => $old_version,
				'new_version' => $new_version,
				'source'      => 'deploy',
				'user_id'     => 0,
			]
		);

		return 1;
	}
}
