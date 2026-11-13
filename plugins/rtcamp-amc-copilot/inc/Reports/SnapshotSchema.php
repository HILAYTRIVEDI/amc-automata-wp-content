<?php
/**
 * Report snapshot format: every section and field from the plan's field catalogue.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports;

/**
 * Class SnapshotSchema
 *
 * FROZEN CONTRACT (Wave 0). Human version: docs/report-snapshot.md.
 *
 * Where each field's value lives:
 *
 *   source 'auto'     Detected. Written by the Generator (D1) into the
 *                     `_amc_snapshot` post meta under sections.<section>.<field>.
 *   source 'saved'    Carried forward. The Generator copies the value from
 *                     the `amc_saved_fields` option into the snapshot (same
 *                     place as auto). On publish, changed values are written
 *                     back to the option (D1).
 *   source 'engineer' Typed per report. NOT in the snapshot. Stored in the
 *                     section block's `engineer` attribute (RichText HTML).
 *
 * Section block attributes (every section block, see block.json):
 *   hidden    bool                 "Hide this section".
 *   overrides object field => str  Engineer override of an auto/saved value
 *                                  (shows an "Edited" badge in the editor).
 *   engineer  object field => html Engineer fields.
 *
 * Effective value of an auto/saved field when rendering:
 *   overrides[field] if set, else snapshot.sections[section][field], else null.
 *
 * Field `type` values: string, html, bool, int, float, date ('Y-m-d'),
 * datetime (ISO 8601), url, list (array of objects, `items` describes the
 * keys), object (`keys` describes the keys), map (object keyed by slug).
 *
 * Snapshot JSON (stored as a JSON string in post meta `_amc_snapshot`):
 *
 *   {
 *     "schema_version": 1,
 *     "taken_at": "2026-11-01T06:00:00Z",
 *     "period": { "type": "month", "start": "2026-10-01", "end": "2026-10-31" },
 *     "site": { "url": "...", "name": "...", "blog_id": 1, "environment": "production" },
 *     "sections": { "overview": { "website_url": "...", ... }, "cms": { ... }, ... }
 *   }
 *
 * Every section key is present; a value the generator could not detect is null.
 *
 * @since 0.1.0
 */
final class SnapshotSchema {

	/**
	 * Snapshot format version. Bump only with a migration for stored reports.
	 */
	public const VERSION = 1;

	/**
	 * Post meta key holding the snapshot JSON.
	 */
	public const META_KEY = '_amc_snapshot';

	/**
	 * Every section, in rtCamp report order. Each section maps to one block
	 * `amc/section-<key with _ replaced by ->`.
	 *
	 * @return array<string, array{title: string, block: string, fields: array<string, array<string, mixed>>}>
	 */
	public static function sections(): array {
		return [
			'overview'         => [
				'title'  => __( 'Overview', 'rtcamp-amc-copilot' ),
				'block'  => 'amc/section-overview',
				'fields' => [
					'website_url'          => self::field( 'auto', 'url', __( 'Website URL', 'rtcamp-amc-copilot' ) ),
					'developer_name'       => self::field( 'auto', 'string', __( 'Developer', 'rtcamp-amc-copilot' ), 'Report author display name.' ),
					'report_date'          => self::field( 'auto', 'date', __( 'Report date', 'rtcamp-amc-copilot' ) ),
					'client_name'          => self::field( 'saved', 'string', __( 'Client', 'rtcamp-amc-copilot' ) ),
					'maintenance_schedule' => self::field( 'saved', 'string', __( 'Maintenance schedule', 'rtcamp-amc-copilot' ) ),
					'hours_used'           => self::field( 'engineer', 'html', __( 'Hours used', 'rtcamp-amc-copilot' ) ),
				],
			],
			'cms'              => [
				'title'  => __( 'CMS', 'rtcamp-amc-copilot' ),
				'block'  => 'amc/section-cms',
				'fields' => [
					'wp_version_old'   => self::field( 'auto', 'string', __( 'WordPress version at period start', 'rtcamp-amc-copilot' ) ),
					'wp_version_new'   => self::field( 'auto', 'string', __( 'WordPress version at period end', 'rtcamp-amc-copilot' ) ),
					'editor_type'      => self::field( 'auto', 'string', __( 'Editor', 'rtcamp-amc-copilot' ), "'block' | 'classic' | 'mixed'" ),
					'is_multisite'     => self::field( 'auto', 'bool', __( 'Multisite', 'rtcamp-amc-copilot' ) ),
					'websites_covered' => self::field( 'saved', 'string', __( 'Websites covered', 'rtcamp-amc-copilot' ) ),
				],
			],
			'theme'            => [
				'title'  => __( 'Theme', 'rtcamp-amc-copilot' ),
				'block'  => 'amc/section-theme',
				'fields' => [
					'active_theme' => self::field( 'auto', 'object', __( 'Active theme', 'rtcamp-amc-copilot' ), '', [ 'keys' => [ 'name', 'slug', 'version' ] ] ),
					'parent_theme' => self::field( 'auto', 'object', __( 'Parent theme', 'rtcamp-amc-copilot' ), 'null when not a child theme.', [ 'keys' => [ 'name', 'slug', 'version' ] ] ),
					'theme_origin' => self::field( 'saved', 'string', __( 'Theme origin', 'rtcamp-amc-copilot' ), 'e.g. custom, marketplace.' ),
				],
			],
			'plugins_themes'   => [
				'title'  => __( 'Plugins and themes', 'rtcamp-amc-copilot' ),
				'block'  => 'amc/section-plugins-themes',
				'fields' => [
					'counts'         => self::field( 'auto', 'object', __( 'Counts', 'rtcamp-amc-copilot' ), '', [ 'keys' => [ 'installed', 'active', 'updated', 'added', 'removed', 'inactive', 'themes_installed', 'themes_updated' ] ] ),
					'updated'        => self::field( 'auto', 'list', __( 'Updated', 'rtcamp-amc-copilot' ), 'From amc_changes action=update in the period.', [ 'items' => [ 'type', 'slug', 'name', 'old_version', 'new_version', 'date', 'source' ] ] ),
					'added'          => self::field( 'auto', 'list', __( 'Added', 'rtcamp-amc-copilot' ), 'action=install.', [ 'items' => [ 'type', 'slug', 'name', 'version', 'date' ] ] ),
					'removed'        => self::field( 'auto', 'list', __( 'Removed', 'rtcamp-amc-copilot' ), 'action=delete.', [ 'items' => [ 'type', 'slug', 'name', 'version', 'date' ] ] ),
					'inactive'       => self::field( 'auto', 'list', __( 'Inactive', 'rtcamp-amc-copilot' ), 'At snapshot time.', [ 'items' => [ 'slug', 'name', 'version' ] ] ),
					'change_reasons' => self::field( 'engineer', 'map', __( 'Reason for each added or removed plugin', 'rtcamp-amc-copilot' ), 'slug => html.' ),
				],
			],
			'database_backups' => [
				'title'  => __( 'Database backups', 'rtcamp-amc-copilot' ),
				'block'  => 'amc/section-database-backups',
				'fields' => [
					'backup_method'    => self::field( 'saved', 'string', __( 'Method', 'rtcamp-amc-copilot' ) ),
					'backup_schedule'  => self::field( 'saved', 'string', __( 'Schedule', 'rtcamp-amc-copilot' ) ),
					'backup_retention' => self::field( 'saved', 'string', __( 'Retention', 'rtcamp-amc-copilot' ) ),
					'backups_working'  => self::field( 'engineer', 'html', __( 'Working correctly', 'rtcamp-amc-copilot' ) ),
					'latest_backup'    => self::field( 'engineer', 'html', __( 'Most recent backup', 'rtcamp-amc-copilot' ) ),
				],
			],
			'user_management'  => [
				'title'  => __( 'User management', 'rtcamp-amc-copilot' ),
				'block'  => 'amc/section-user-management',
				'fields' => [
					'total_users'    => self::field( 'auto', 'int', __( 'Total users', 'rtcamp-amc-copilot' ) ),
					'users_per_role' => self::field( 'auto', 'list', __( 'Users per role', 'rtcamp-amc-copilot' ), '', [ 'items' => [ 'role', 'label', 'count' ] ] ),
					'administrators' => self::field( 'auto', 'list', __( 'Administrators', 'rtcamp-amc-copilot' ), '', [ 'items' => [ 'login', 'display_name', 'email' ] ] ),
					'users_added'    => self::field( 'auto', 'list', __( 'Users added', 'rtcamp-amc-copilot' ), '', [ 'items' => [ 'login', 'role', 'date' ] ] ),
					'users_removed'  => self::field( 'auto', 'list', __( 'Users removed', 'rtcamp-amc-copilot' ), '', [ 'items' => [ 'login', 'date' ] ] ),
				],
			],
			'hosting'          => [
				'title'  => __( 'Hosting', 'rtcamp-amc-copilot' ),
				'block'  => 'amc/section-hosting',
				'fields' => [
					'host'                    => self::field( 'auto', 'string', __( 'Host', 'rtcamp-amc-copilot' ), 'null when not detectable.' ),
					'server_type'             => self::field( 'auto', 'string', __( 'Server type', 'rtcamp-amc-copilot' ) ),
					'web_server'              => self::field( 'auto', 'string', __( 'Web server', 'rtcamp-amc-copilot' ) ),
					'mysql_version'           => self::field( 'auto', 'string', __( 'MySQL version', 'rtcamp-amc-copilot' ) ),
					'php_version'             => self::field( 'auto', 'string', __( 'PHP version', 'rtcamp-amc-copilot' ) ),
					'memory_limit'            => self::field( 'auto', 'string', __( 'Memory limit', 'rtcamp-amc-copilot' ) ),
					'php_recommendation'      => self::field( 'auto', 'string', __( 'PHP update recommendation', 'rtcamp-amc-copilot' ) ),
					'hosting_type'            => self::field( 'saved', 'string', __( 'Hosting type', 'rtcamp-amc-copilot' ) ),
					'control_panel'           => self::field( 'saved', 'string', __( 'Control panel', 'rtcamp-amc-copilot' ) ),
					'server_backup_frequency' => self::field( 'saved', 'string', __( 'Server backup frequency', 'rtcamp-amc-copilot' ) ),
					'server_backup_retention' => self::field( 'saved', 'string', __( 'Server backup retention', 'rtcamp-amc-copilot' ) ),
					'redundancy'              => self::field( 'saved', 'string', __( 'Redundancy', 'rtcamp-amc-copilot' ) ),
					'account_owners'          => self::field( 'saved', 'string', __( 'Account owners', 'rtcamp-amc-copilot' ) ),
					'hosting_users'           => self::field( 'saved', 'string', __( 'Hosting users', 'rtcamp-amc-copilot' ) ),
					'disk_space'              => self::field( 'engineer', 'html', __( 'Disk space', 'rtcamp-amc-copilot' ) ),
					'server_backups_running'  => self::field( 'engineer', 'html', __( 'Server backups running', 'rtcamp-amc-copilot' ) ),
					'latest_server_backup'    => self::field( 'engineer', 'html', __( 'Most recent server backup', 'rtcamp-amc-copilot' ) ),
				],
			],
			'domains'          => [
				'title'  => __( 'Domains', 'rtcamp-amc-copilot' ),
				'block'  => 'amc/section-domains',
				'fields' => [
					'primary_domain' => self::field( 'auto', 'string', __( 'Primary domain', 'rtcamp-amc-copilot' ) ),
					'domain_expiry'  => self::field( 'saved', 'string', __( 'Expiry', 'rtcamp-amc-copilot' ) ),
					'auto_renewal'   => self::field( 'saved', 'string', __( 'Auto-renewal', 'rtcamp-amc-copilot' ) ),
					'subdomains'     => self::field( 'saved', 'string', __( 'Subdomains', 'rtcamp-amc-copilot' ) ),
				],
			],
			'ssl'              => [
				'title'  => __( 'SSL', 'rtcamp-amc-copilot' ),
				'block'  => 'amc/section-ssl',
				'fields' => [
					'https'            => self::field( 'auto', 'bool', __( 'HTTPS', 'rtcamp-amc-copilot' ) ),
					'issuer'           => self::field( 'auto', 'string', __( 'Issuer', 'rtcamp-amc-copilot' ), 'null = unavailable (becomes engineer-entered via overrides).' ),
					'expires_at'       => self::field( 'auto', 'date', __( 'Expiry', 'rtcamp-amc-copilot' ) ),
					'days_left'        => self::field( 'auto', 'int', __( 'Days left', 'rtcamp-amc-copilot' ) ),
					'ssl_type'         => self::field( 'saved', 'string', __( 'Purchased or free', 'rtcamp-amc-copilot' ) ),
					'ssl_auto_renewal' => self::field( 'saved', 'string', __( 'Auto-renewal', 'rtcamp-amc-copilot' ) ),
				],
			],
			'caching_cdn'      => [
				'title'  => __( 'Caching and CDN', 'rtcamp-amc-copilot' ),
				'block'  => 'amc/section-caching-cdn',
				'fields' => [
					'page_cache'           => self::field( 'auto', 'string', __( 'Page cache', 'rtcamp-amc-copilot' ) ),
					'object_cache'         => self::field( 'auto', 'string', __( 'Object cache', 'rtcamp-amc-copilot' ) ),
					'cdn'                  => self::field( 'auto', 'string', __( 'CDN', 'rtcamp-amc-copilot' ) ),
					'configured_correctly' => self::field( 'engineer', 'html', __( 'Configured correctly', 'rtcamp-amc-copilot' ) ),
				],
			],
			'security'         => [
				'title'  => __( 'Security', 'rtcamp-amc-copilot' ),
				'block'  => 'amc/section-security',
				'fields' => [
					'vulnerabilities'   => self::field( 'auto', 'list', __( 'Vulnerabilities', 'rtcamp-amc-copilot' ), 'vuln.match findings in the period.', [ 'items' => [ 'component', 'reference', 'severity', 'status', 'fixed_in', 'first_seen', 'resolved_at' ] ] ),
					'core_checksums'    => self::field( 'auto', 'object', __( 'Core checksums', 'rtcamp-amc-copilot' ), '', [ 'keys' => [ 'status', 'modified_files' ] ] ),
					'config_checks'     => self::field( 'auto', 'list', __( 'Configuration checks', 'rtcamp-amc-copilot' ), '', [ 'items' => [ 'id', 'label', 'status' ] ] ),
					'remediation_notes' => self::field( 'engineer', 'html', __( 'Remediation notes', 'rtcamp-amc-copilot' ) ),
					'malware_scan'      => self::field( 'engineer', 'html', __( 'Malware scan result', 'rtcamp-amc-copilot' ) ),
				],
			],
			'forms'            => [
				'title'  => __( 'Forms', 'rtcamp-amc-copilot' ),
				'block'  => 'amc/section-forms',
				'fields' => [
					'form_plugins'            => self::field( 'auto', 'list', __( 'Form plugins', 'rtcamp-amc-copilot' ), '', [ 'items' => [ 'name', 'slug', 'form_count', 'addons' ] ] ),
					'sending_method'          => self::field( 'auto', 'string', __( 'Sending method', 'rtcamp-amc-copilot' ) ),
					'integrations'            => self::field( 'auto', 'list', __( 'Integrations', 'rtcamp-amc-copilot' ), '', [ 'items' => [ 'name' ] ] ),
					'entries_saving'          => self::field( 'engineer', 'html', __( 'Entries saving', 'rtcamp-amc-copilot' ) ),
					'notifications_confirmed' => self::field( 'engineer', 'html', __( 'Notifications confirmed', 'rtcamp-amc-copilot' ) ),
				],
			],
			'analytics'        => [
				'title'  => __( 'Analytics and tagging', 'rtcamp-amc-copilot' ),
				'block'  => 'amc/section-analytics',
				'fields' => [
					'gtm_ids'       => self::field( 'auto', 'list', __( 'GTM IDs', 'rtcamp-amc-copilot' ), 'List of strings.' ),
					'ga_ids'        => self::field( 'auto', 'list', __( 'GA IDs', 'rtcamp-amc-copilot' ), 'List of strings.' ),
					'tag_assistant' => self::field( 'engineer', 'html', __( 'Tag Assistant warnings and errors', 'rtcamp-amc-copilot' ) ),
				],
			],
			'accessibility'    => [
				'title'  => __( 'Accessibility', 'rtcamp-amc-copilot' ),
				'block'  => 'amc/section-accessibility',
				'fields' => [
					'third_party_tool' => self::field( 'auto', 'string', __( 'Third-party tool', 'rtcamp-amc-copilot' ) ),
					'statement_page'   => self::field( 'auto', 'url', __( 'Statement page', 'rtcamp-amc-copilot' ) ),
					'defined_standard' => self::field( 'saved', 'string', __( 'Defined standard', 'rtcamp-amc-copilot' ) ),
					'wave_result'      => self::field( 'engineer', 'html', __( 'WAVE result', 'rtcamp-amc-copilot' ) ),
				],
			],
			'php_errors'       => [
				'title'  => __( 'PHP errors', 'rtcamp-amc-copilot' ),
				'block'  => 'amc/section-php-errors',
				'fields' => [
					'fatal_count'       => self::field( 'auto', 'int', __( 'Fatal errors', 'rtcamp-amc-copilot' ) ),
					'warning_count'     => self::field( 'auto', 'int', __( 'Warnings and notices', 'rtcamp-amc-copilot' ) ),
					'deprecation_count' => self::field( 'auto', 'int', __( 'Deprecations', 'rtcamp-amc-copilot' ) ),
					'top_signatures'    => self::field( 'auto', 'list', __( 'Top signatures', 'rtcamp-amc-copilot' ), 'Max 10.', [ 'items' => [ 'type', 'message', 'source', 'count' ] ] ),
				],
			],
			'smoke_checks'     => [
				'title'  => __( 'Smoke checks', 'rtcamp-amc-copilot' ),
				'block'  => 'amc/section-smoke-checks',
				'fields' => [
					'urls' => self::field( 'auto', 'list', __( 'URLs', 'rtcamp-amc-copilot' ), '', [ 'items' => [ 'url', 'runs', 'pass_rate', 'avg_response_ms' ] ] ),
				],
			],
			'performance'      => [
				'title'  => __( 'Performance', 'rtcamp-amc-copilot' ),
				'block'  => 'amc/section-performance',
				'fields' => [
					'response_trend'      => self::field( 'auto', 'list', __( 'Response time trend', 'rtcamp-amc-copilot' ), 'Weekly points for monthly reports, monthly points for quarterly/annual.', [ 'items' => [ 'label', 'start', 'avg_ms' ] ] ),
					'database_size_bytes' => self::field( 'auto', 'int', __( 'Database size', 'rtcamp-amc-copilot' ) ),
					'autoload_size_bytes' => self::field( 'auto', 'int', __( 'Autoloaded options size', 'rtcamp-amc-copilot' ) ),
				],
			],
			'engineer_notes'   => [
				'title'  => __( "Engineer's notes", 'rtcamp-amc-copilot' ),
				'block'  => 'amc/section-engineer-notes',
				'fields' => [
					'note' => self::field( 'engineer', 'html', __( 'Note', 'rtcamp-amc-copilot' ) ),
				],
			],
		];
	}

	/**
	 * Fields of one section filtered by source.
	 *
	 * @param string $section Section key.
	 * @param string $source  'auto' | 'saved' | 'engineer'.
	 *
	 * @return list<string> Field keys.
	 */
	public static function fields_by_source( string $section, string $source ): array {
		$fields = self::sections()[ $section ]['fields'] ?? [];

		return array_keys( array_filter( $fields, static fn ( array $field ): bool => $field['source'] === $source ) );
	}

	/**
	 * Every saved field, as section => list<field> (shape of `amc_saved_fields`).
	 *
	 * @return array<string, list<string>>
	 */
	public static function saved_fields(): array {
		$saved = [];

		foreach ( array_keys( self::sections() ) as $section ) {
			$fields = self::fields_by_source( $section, 'saved' );
			if ( [] !== $fields ) {
				$saved[ $section ] = $fields;
			}
		}

		return $saved;
	}

	/**
	 * An empty snapshot: every section present, every auto/saved field null.
	 *
	 * @return array<string, mixed>
	 */
	public static function empty_snapshot(): array {
		$sections = [];

		foreach ( self::sections() as $key => $section ) {
			$sections[ $key ] = [];
			foreach ( $section['fields'] as $field_key => $field ) {
				if ( 'engineer' !== $field['source'] ) {
					$sections[ $key ][ $field_key ] = null;
				}
			}
		}

		return [
			'schema_version' => self::VERSION,
			'taken_at'       => null,
			'period'         => [
				'type'  => null,
				'start' => null,
				'end'   => null,
			],
			'site'           => [
				'url'         => null,
				'name'        => null,
				'blog_id'     => null,
				'environment' => null,
			],
			'sections'       => $sections,
		];
	}

	/**
	 * Read and decode a report's snapshot (empty snapshot when missing/invalid).
	 *
	 * @param int $post_id Report post ID.
	 *
	 * @return array<string, mixed>
	 */
	public static function for_post( int $post_id ): array {
		$raw     = get_post_meta( $post_id, self::META_KEY, true );
		$decoded = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;

		if ( ! is_array( $decoded ) ) {
			return self::empty_snapshot();
		}

		return array_replace_recursive( self::empty_snapshot(), $decoded );
	}

	/**
	 * Field definition helper.
	 *
	 * @param string               $source      'auto' | 'saved' | 'engineer'.
	 * @param string               $type        Value type.
	 * @param string               $label       Translated label.
	 * @param string               $description Notes for implementers.
	 * @param array<string, mixed> $extra       'items' or 'keys'.
	 *
	 * @return array<string, mixed>
	 */
	private static function field( string $source, string $type, string $label, string $description = '', array $extra = [] ): array {
		return array_merge(
			[
				'source'      => $source,
				'type'        => $type,
				'label'       => $label,
				'description' => $description,
			],
			$extra
		);
	}
}
