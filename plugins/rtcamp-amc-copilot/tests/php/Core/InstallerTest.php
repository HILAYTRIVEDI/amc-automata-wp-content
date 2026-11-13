<?php
/**
 * Tests for the Installer (tables, schema version).
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Core;

use AMC\Core\Installer;
use AMC\Storage\Tables;
use AMC\Tests\TestCase;

/**
 * Class InstallerTest
 */
final class InstallerTest extends TestCase {

	public function test_all_seven_tables_exist_with_blog_id(): void {
		global $wpdb;

		$this->assertCount( 7, Tables::ALL );

		foreach ( Tables::ALL as $table ) {
			$columns = $wpdb->get_col( 'DESCRIBE ' . Tables::name( $table ) ); // phpcs:ignore WordPress.DB
			$this->assertNotEmpty( $columns, "Missing table {$table}" );

			if ( Tables::LOCKS !== $table ) {
				$this->assertContains( 'blog_id', $columns, "{$table} has no blog_id" );
			}
		}
	}

	public function test_tables_use_the_network_base_prefix(): void {
		global $wpdb;

		$this->assertSame( $wpdb->base_prefix . 'amc_runs', Tables::name( Tables::RUNS ) );
	}

	public function test_hourly_aggregates_have_unique_keys_for_upserts(): void {
		global $wpdb;

		foreach ( [ Tables::EVENTS, Tables::QUERIES ] as $table ) {
			$index = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', Tables::name( $table ), 'blog_signature_hour' ), ARRAY_A ); // phpcs:ignore WordPress.DB
			$this->assertCount( 3, $index, "{$table} needs UNIQUE (blog_id, signature, hour)" );
			$this->assertSame( '0', (string) $index[0]['Non_unique'] );
		}
	}

	public function test_schema_version_and_settings_are_not_autoloaded(): void {
		global $wpdb;

		Installer::install();

		$this->assertSame( Installer::SCHEMA_VERSION, get_option( Installer::VERSION_OPTION ) );

		$autoload = $wpdb->get_col( "SELECT autoload FROM {$wpdb->options} WHERE option_name IN ('amc_schema_version', 'amc_settings')" ); // phpcs:ignore WordPress.DB
		$this->assertNotEmpty( $autoload );
		foreach ( $autoload as $value ) {
			$this->assertContains( $value, [ 'no', 'off' ] );
		}
	}

	public function test_maybe_upgrade_reinstalls_when_version_is_behind(): void {
		update_option( Installer::VERSION_OPTION, '0', false );

		Installer::maybe_upgrade();

		$this->assertSame( Installer::SCHEMA_VERSION, get_option( Installer::VERSION_OPTION ) );
	}
}
