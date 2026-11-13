<?php
/**
 * Tests for the report field catalogue.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Reports;

use AMC\Reports\FieldCatalogue;
use AMC\Reports\SnapshotSchema;
use AMC\Tests\TestCase;

/**
 * Class FieldCatalogueTest
 *
 * The expected sets below are the plan's "Field catalogue" table, typed out by
 * hand. They must match SnapshotSchema, which the Generator and the section
 * blocks both build on.
 */
final class FieldCatalogueTest extends TestCase {

	/**
	 * The plan's catalogue: section => [ auto, saved, engineer ] field keys.
	 *
	 * @return array<string, array{0: list<string>, 1: list<string>, 2: list<string>}>
	 */
	private static function plan(): array {
		return [
			'overview'         => [ [ 'website_url', 'developer_name', 'report_date' ], [ 'client_name', 'maintenance_schedule' ], [ 'hours_used' ] ],
			'cms'              => [ [ 'wp_version_old', 'wp_version_new', 'editor_type', 'is_multisite' ], [ 'websites_covered' ], [] ],
			'theme'            => [ [ 'active_theme', 'parent_theme' ], [ 'theme_origin' ], [] ],
			'plugins_themes'   => [ [ 'counts', 'updated', 'added', 'removed', 'inactive' ], [], [ 'change_reasons' ] ],
			'database_backups' => [ [], [ 'backup_method', 'backup_schedule', 'backup_retention' ], [ 'backups_working', 'latest_backup' ] ],
			'user_management'  => [ [ 'total_users', 'users_per_role', 'administrators', 'users_added', 'users_removed' ], [], [] ],
			'hosting'          => [
				[ 'host', 'server_type', 'web_server', 'mysql_version', 'php_version', 'memory_limit', 'php_recommendation' ],
				[ 'hosting_type', 'control_panel', 'server_backup_frequency', 'server_backup_retention', 'redundancy', 'account_owners', 'hosting_users' ],
				[ 'disk_space', 'server_backups_running', 'latest_server_backup' ],
			],
			'domains'          => [ [ 'primary_domain' ], [ 'domain_expiry', 'auto_renewal', 'subdomains' ], [] ],
			'ssl'              => [ [ 'https', 'issuer', 'expires_at', 'days_left' ], [ 'ssl_type', 'ssl_auto_renewal' ], [] ],
			'caching_cdn'      => [ [ 'page_cache', 'object_cache', 'cdn' ], [], [ 'configured_correctly' ] ],
			'security'         => [ [ 'vulnerabilities', 'core_checksums', 'config_checks' ], [], [ 'remediation_notes', 'malware_scan' ] ],
			'forms'            => [ [ 'form_plugins', 'sending_method', 'integrations' ], [], [ 'entries_saving', 'notifications_confirmed' ] ],
			'analytics'        => [ [ 'gtm_ids', 'ga_ids' ], [], [ 'tag_assistant' ] ],
			'accessibility'    => [ [ 'third_party_tool', 'statement_page' ], [ 'defined_standard' ], [ 'wave_result' ] ],
			'php_errors'       => [ [ 'fatal_count', 'warning_count', 'deprecation_count', 'top_signatures' ], [], [] ],
			'smoke_checks'     => [ [ 'urls' ], [], [] ],
			'performance'      => [ [ 'response_trend', 'database_size_bytes', 'autoload_size_bytes' ], [], [] ],
			'engineer_notes'   => [ [], [], [ 'note' ] ],
		];
	}

	public function test_sections_match_the_plan_in_report_order(): void {
		$this->assertSame( array_keys( self::plan() ), FieldCatalogue::sections() );
	}

	public function test_every_field_has_the_source_the_plan_gives_it(): void {
		foreach ( self::plan() as $section => [ $auto, $saved, $engineer ] ) {
			$this->assertEqualsCanonicalizing( $auto, SnapshotSchema::fields_by_source( $section, 'auto' ), "{$section}: auto" );
			$this->assertEqualsCanonicalizing( $saved, SnapshotSchema::fields_by_source( $section, 'saved' ), "{$section}: saved" );
			$this->assertEqualsCanonicalizing( $engineer, SnapshotSchema::fields_by_source( $section, 'engineer' ), "{$section}: engineer" );

			$this->assertEqualsCanonicalizing( $auto, FieldCatalogue::auto()[ $section ] ?? [], "{$section}: catalogue auto" );
			$this->assertEqualsCanonicalizing( $saved, FieldCatalogue::saved()[ $section ] ?? [], "{$section}: catalogue saved" );
			$this->assertEqualsCanonicalizing( $engineer, FieldCatalogue::engineer()[ $section ] ?? [], "{$section}: catalogue engineer" );
		}
	}

	public function test_source_lookup_and_unknown_fields(): void {
		$this->assertSame( 'auto', FieldCatalogue::source( 'ssl', 'issuer' ) );
		$this->assertSame( 'saved', FieldCatalogue::source( 'ssl', 'ssl_type' ) );
		$this->assertSame( 'engineer', FieldCatalogue::source( 'overview', 'hours_used' ) );
		$this->assertNull( FieldCatalogue::source( 'ssl', 'nope' ) );
		$this->assertNull( FieldCatalogue::source( 'nope', 'issuer' ) );
		$this->assertTrue( FieldCatalogue::in_snapshot( 'overview', 'client_name' ) );
		$this->assertFalse( FieldCatalogue::in_snapshot( 'overview', 'hours_used' ) );
	}

	public function test_counts_add_up_and_auto_is_the_largest_share(): void {
		$counts = FieldCatalogue::counts();

		$this->assertSame( $counts['total'], $counts['auto'] + $counts['saved'] + $counts['engineer'] );
		$this->assertGreaterThan( $counts['saved'], $counts['auto'] );
		$this->assertGreaterThan( $counts['engineer'], $counts['auto'] );
		$this->assertSame( $counts['saved'], array_sum( array_map( 'count', SnapshotSchema::saved_fields() ) ) );
	}

	public function test_the_empty_snapshot_has_every_auto_and_saved_field_and_no_engineer_field(): void {
		$empty = SnapshotSchema::empty_snapshot();

		foreach ( self::plan() as $section => [ $auto, $saved, $engineer ] ) {
			$this->assertArrayHasKey( $section, $empty['sections'] );

			foreach ( array_merge( $auto, $saved ) as $field ) {
				$this->assertArrayHasKey( $field, $empty['sections'][ $section ], "{$section}.{$field}" );
			}

			foreach ( $engineer as $field ) {
				$this->assertArrayNotHasKey( $field, $empty['sections'][ $section ], "{$section}.{$field} is not stored in the snapshot" );
			}
		}
	}
}
