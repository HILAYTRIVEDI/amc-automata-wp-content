<?php
/**
 * Tests for the report snapshot schema.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Reports;

use AMC\Reports\SnapshotSchema;
use AMC\Tests\TestCase;

/**
 * Class SnapshotSchemaTest
 */
final class SnapshotSchemaTest extends TestCase {

	public function test_eighteen_sections_in_report_order(): void {
		$this->assertSame(
			[ 'overview', 'cms', 'theme', 'plugins_themes', 'database_backups', 'user_management', 'hosting', 'domains', 'ssl', 'caching_cdn', 'security', 'forms', 'analytics', 'accessibility', 'php_errors', 'smoke_checks', 'performance', 'engineer_notes' ],
			array_keys( SnapshotSchema::sections() )
		);
	}

	public function test_every_field_is_well_formed_and_block_names_follow_the_key(): void {
		foreach ( SnapshotSchema::sections() as $key => $section ) {
			$this->assertSame( 'amc/section-' . str_replace( '_', '-', $key ), $section['block'] );
			$this->assertNotEmpty( $section['fields'] );

			foreach ( $section['fields'] as $field => $def ) {
				$this->assertMatchesRegularExpression( '/^[a-z0-9_]+$/', $field );
				$this->assertContains( $def['source'], [ 'auto', 'saved', 'engineer' ], "{$key}.{$field}" );
				$this->assertContains( $def['type'], [ 'string', 'html', 'bool', 'int', 'float', 'date', 'datetime', 'url', 'list', 'object', 'map' ], "{$key}.{$field}" );
			}
		}
	}

	public function test_empty_snapshot_has_auto_and_saved_fields_only(): void {
		$snapshot = SnapshotSchema::empty_snapshot();

		$this->assertSame( SnapshotSchema::VERSION, $snapshot['schema_version'] );
		$this->assertArrayHasKey( 'website_url', $snapshot['sections']['overview'] );
		$this->assertArrayHasKey( 'client_name', $snapshot['sections']['overview'] );
		$this->assertArrayNotHasKey( 'hours_used', $snapshot['sections']['overview'] );
		$this->assertSame( [], $snapshot['sections']['engineer_notes'] );
	}

	public function test_saved_fields_map(): void {
		$saved = SnapshotSchema::saved_fields();

		$this->assertSame( [ 'client_name', 'maintenance_schedule' ], $saved['overview'] );
		$this->assertArrayNotHasKey( 'php_errors', $saved );
	}

	public function test_for_post_merges_stored_json_over_the_empty_snapshot(): void {
		$post = self::factory()->post->create( [ 'post_type' => 'amc_report' ] );
		update_post_meta( $post, SnapshotSchema::META_KEY, wp_slash( wp_json_encode( [ 'sections' => [ 'overview' => [ 'website_url' => 'https://a.test' ] ] ] ) ) );

		$snapshot = SnapshotSchema::for_post( $post );

		$this->assertSame( 'https://a.test', $snapshot['sections']['overview']['website_url'] );
		$this->assertArrayHasKey( 'cms', $snapshot['sections'] );
		$this->assertSame( SnapshotSchema::empty_snapshot(), SnapshotSchema::for_post( 0 ) );
	}
}
