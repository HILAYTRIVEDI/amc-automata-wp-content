<?php
/**
 * Installer: creates and upgrades the custom tables with dbDelta().
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Core;

use AMC\Storage\Tables;

/**
 * Class Installer
 *
 * FROZEN SCHEMA (Wave 0). To change a table after the freeze: bump
 * SCHEMA_VERSION, edit the CREATE TABLE below (dbDelta adds columns and
 * indexes; it never drops them) and update docs/CONTRACTS.md § Tables.
 *
 * Conventions every lane relies on:
 * - All DATETIME columns are UTC, 'Y-m-d H:i:s'. Use Storage\AbstractRepository::now().
 * - JSON columns (`data`) are LONGTEXT holding wp_json_encode() output;
 *   repositories encode on write and decode on read.
 * - `blog_id` is on every table except amc_locks (lock names include it).
 *
 * dbDelta formatting rules (do not "tidy" the SQL): one column per line,
 * two spaces after PRIMARY KEY, KEY not INDEX, no backticks.
 *
 * @since 0.1.0
 */
final class Installer {

	/**
	 * Current schema version, stored in the `amc_schema_version` option.
	 */
	public const SCHEMA_VERSION = '1';

	/**
	 * Option holding the installed schema version (autoload off).
	 */
	public const VERSION_OPTION = 'amc_schema_version';

	/**
	 * Create or upgrade all tables and seed options.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( self::schema( $wpdb->get_charset_collate() ) );

		// Seed the settings option with autoload OFF (add_option is a no-op if it exists).
		add_option( Settings::OPTION, Settings::defaults(), '', false );

		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Run install() when the stored schema version is behind.
	 *
	 * Called from admin_init, the scheduler tick and WP-CLI; never on
	 * front-end requests (it reads a non-autoloaded option).
	 */
	public static function maybe_upgrade(): void {
		if ( (string) get_option( self::VERSION_OPTION, '' ) !== self::SCHEMA_VERSION ) {
			self::install();
		}
	}

	/**
	 * Drop every table and delete the schema option (uninstall only).
	 */
	public static function uninstall(): void {
		global $wpdb;

		foreach ( Tables::ALL as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . Tables::name( $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall; table name from a constant.
		}

		delete_option( self::VERSION_OPTION );
	}

	/**
	 * The CREATE TABLE statements.
	 *
	 * @param string $charset_collate Charset/collation clause.
	 *
	 * @return list<string>
	 */
	public static function schema( string $charset_collate ): array {
		$runs      = Tables::name( Tables::RUNS );
		$findings  = Tables::name( Tables::FINDINGS );
		$events    = Tables::name( Tables::EVENTS );
		$queries   = Tables::name( Tables::QUERIES );
		$changes   = Tables::name( Tables::CHANGES );
		$snapshots = Tables::name( Tables::SNAPSHOTS );
		$locks     = Tables::name( Tables::LOCKS );

		return [
			// One row per check execution (also the "Run now" queue: status 'queued').
			"CREATE TABLE {$runs} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
blog_id bigint(20) unsigned NOT NULL DEFAULT 1,
check_id varchar(64) NOT NULL,
trigger_type varchar(16) NOT NULL DEFAULT 'cron',
status varchar(16) NOT NULL DEFAULT 'queued',
summary varchar(255) NOT NULL DEFAULT '',
data longtext NULL,
user_id bigint(20) unsigned NOT NULL DEFAULT 0,
created_at datetime NOT NULL,
started_at datetime NULL,
finished_at datetime NULL,
duration_ms int(10) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (id),
KEY blog_check_created (blog_id,check_id,created_at),
KEY blog_status_created (blog_id,status,created_at),
KEY created_at (created_at)
) {$charset_collate};",

			// One row per distinct issue occurrence (open -> resolved; a comeback is a new row).
			"CREATE TABLE {$findings} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
blog_id bigint(20) unsigned NOT NULL DEFAULT 1,
check_id varchar(64) NOT NULL,
dedupe_key varchar(191) NOT NULL,
severity varchar(16) NOT NULL DEFAULT 'info',
title varchar(255) NOT NULL DEFAULT '',
data longtext NULL,
status varchar(16) NOT NULL DEFAULT 'open',
note text NULL,
run_id bigint(20) unsigned NOT NULL DEFAULT 0,
first_seen datetime NOT NULL,
last_seen datetime NOT NULL,
resolved_at datetime NULL,
alerted_at datetime NULL,
PRIMARY KEY  (id),
KEY blog_check_key (blog_id,check_id,dedupe_key),
KEY blog_status_severity (blog_id,status,severity),
KEY run_id (run_id),
KEY resolved_at (resolved_at)
) {$charset_collate};",

			// Captured error/deprecation signature, aggregated per hour (upserted).
			"CREATE TABLE {$events} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
blog_id bigint(20) unsigned NOT NULL DEFAULT 1,
type varchar(20) NOT NULL,
signature char(32) NOT NULL,
message text NULL,
source varchar(191) NOT NULL DEFAULT '',
file varchar(255) NOT NULL DEFAULT '',
line int(10) unsigned NOT NULL DEFAULT 0,
hour datetime NOT NULL,
count int(10) unsigned NOT NULL DEFAULT 0,
last_seen datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY blog_signature_hour (blog_id,signature,hour),
KEY blog_type_hour (blog_id,type,hour),
KEY hour (hour)
) {$charset_collate};",

			// Sampled slow query signature, aggregated per hour (upserted).
			"CREATE TABLE {$queries} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
blog_id bigint(20) unsigned NOT NULL DEFAULT 1,
signature char(32) NOT NULL,
sql_sample text NULL,
caller varchar(255) NOT NULL DEFAULT '',
hour datetime NOT NULL,
count int(10) unsigned NOT NULL DEFAULT 0,
max_ms float NOT NULL DEFAULT 0,
avg_ms float NOT NULL DEFAULT 0,
PRIMARY KEY  (id),
UNIQUE KEY blog_signature_hour (blog_id,signature,hour),
KEY hour (hour)
) {$charset_collate};",

			// Change to code or users (hooks, deploy diff, access changes).
			"CREATE TABLE {$changes} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
blog_id bigint(20) unsigned NOT NULL DEFAULT 1,
type varchar(16) NOT NULL,
action varchar(20) NOT NULL,
item varchar(191) NOT NULL DEFAULT '',
item_name varchar(255) NOT NULL DEFAULT '',
old_version varchar(64) NOT NULL DEFAULT '',
new_version varchar(64) NOT NULL DEFAULT '',
source varchar(16) NOT NULL DEFAULT 'admin',
user_id bigint(20) unsigned NOT NULL DEFAULT 0,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY blog_created (blog_id,created_at),
KEY blog_type_created (blog_id,type,created_at),
KEY created_at (created_at)
) {$charset_collate};",

			// Inventory snapshot (weekly, plus whenever a check needs a baseline).
			"CREATE TABLE {$snapshots} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
blog_id bigint(20) unsigned NOT NULL DEFAULT 1,
kind varchar(32) NOT NULL,
data longtext NULL,
taken_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY blog_kind_taken (blog_id,kind,taken_at),
KEY taken_at (taken_at)
) {$charset_collate};",

			// Running job lock; row deleted on finish, ignored after expires_at.
			"CREATE TABLE {$locks} (
name varchar(191) NOT NULL,
owner varchar(64) NOT NULL,
expires_at datetime NOT NULL,
PRIMARY KEY  (name),
KEY expires_at (expires_at)
) {$charset_collate};",
		];
	}
}
