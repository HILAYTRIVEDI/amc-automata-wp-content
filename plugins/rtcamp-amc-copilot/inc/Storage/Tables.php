<?php
/**
 * Custom table names.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Storage;

/**
 * Class Tables
 *
 * The seven AMC tables. They use `$wpdb->base_prefix` (one set of tables for
 * the whole network) and every row carries `blog_id`, so the multisite
 * network view later needs no migration.
 *
 * Always build a table name with Tables::name( Tables::RUNS ), never by hand.
 *
 * @since 0.1.0
 */
final class Tables {

	public const RUNS      = 'amc_runs';
	public const FINDINGS  = 'amc_findings';
	public const EVENTS    = 'amc_events';
	public const QUERIES   = 'amc_queries';
	public const CHANGES   = 'amc_changes';
	public const SNAPSHOTS = 'amc_snapshots';
	public const LOCKS     = 'amc_locks';

	/**
	 * Every table, in creation order.
	 */
	public const ALL = [
		self::RUNS,
		self::FINDINGS,
		self::EVENTS,
		self::QUERIES,
		self::CHANGES,
		self::SNAPSHOTS,
		self::LOCKS,
	];

	/**
	 * Full table name with the network base prefix.
	 *
	 * @param string $table One of the class constants.
	 *
	 * @return string e.g. `wp_amc_runs`.
	 */
	public static function name( string $table ): string {
		global $wpdb;

		return $wpdb->base_prefix . $table;
	}
}
