<?php
/**
 * Tests for the updates.readiness check.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Checks\Updates;

use AMC\Checks\Context;
use AMC\Checks\Severity;
use AMC\Checks\Status;
use AMC\Checks\Updates\Readiness;
use AMC\Tests\TestCase;

/**
 * Class ReadinessTest
 */
final class ReadinessTest extends TestCase {

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		delete_site_transient( 'update_plugins' );

		parent::tear_down();
	}

	/**
	 * Build the check around get_plugins()-shaped data (no "Tested up to" key,
	 * as core never parses it) and a fake tested-up-to source.
	 *
	 * @param array<string, ?string> $tested Plugin file => tested-up-to value.
	 */
	private function check_with( array $tested ): Readiness {
		$plugins = [];
		foreach ( array_keys( $tested ) as $file ) {
			$plugins[ $file ] = [
				'Name'    => ucfirst( strtok( $file, '/' ) ),
				'Version' => '1.0',
			];
		}

		return new Readiness(
			static fn (): string => '7.1.3',
			static fn (): array => $plugins,
			static fn (): array => array_keys( $tested ),
			static fn ( string $file ): ?string => $tested[ $file ] ?? null
		);
	}

	public function test_tested_up_to_comes_from_the_source_not_get_plugins(): void {
		$result = $this->check_with( [ 'foo/foo.php' => '7.2' ] )->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( '7.2', $result->data['plugins'][0]['tested_up_to'] );
		$this->assertTrue( $result->data['plugins'][0]['ready'] );
	}

	public function test_declared_but_old_value_is_reported_with_its_version(): void {
		$result = $this->check_with(
			[
				'foo/foo.php' => '7.1',
				'bar/bar.php' => null,
			]
		)->run( Context::for_tests() );

		$this->assertSame( Status::WARN, $result->status );
		$this->assertSame( '7.2', $result->data['next_major'] );
		$this->assertSame( 2, $result->data['not_ready'] );
		$this->assertSame( Severity::LOW, $result->findings[0]->severity );
		$this->assertSame( 'readiness:foo/foo.php:7.1', $result->findings[0]->dedupe_key );
		$this->assertStringContainsString( '(tested up to: 7.1)', $result->findings[0]->title );
		$this->assertStringContainsString( '(tested up to: not declared)', $result->findings[1]->title );
	}

	public function test_default_source_reads_the_plugin_header(): void {
		// AMC Copilot's own header declares "Tested up to"; get_plugins() drops it.
		\AMC\Support\AdminFunctions::ensure( 'get_plugins' );
		$file = plugin_basename( AMC_COPILOT_FILE );
		$this->assertArrayNotHasKey( 'Tested up to', get_plugin_data( AMC_COPILOT_FILE, false, false ) );

		$header = get_file_data( AMC_COPILOT_FILE, [ 'tested' => 'Tested up to' ] )['tested'];
		$this->assertNotSame( '', $header );
		$this->assertSame( $header, Readiness::tested_up_to( $file ) );
	}

	public function test_default_source_prefers_wordpress_org_data_for_the_installed_version(): void {
		$file = plugin_basename( AMC_COPILOT_FILE );
		set_site_transient(
			'update_plugins',
			(object) [
				'last_checked' => time(),
				'response'     => [],
				'no_update'    => [ $file => (object) [ 'tested' => '9.9' ] ],
			]
		);

		$this->assertSame( '9.9', Readiness::tested_up_to( $file ) );
	}

	public function test_default_source_returns_null_for_a_missing_plugin(): void {
		$this->assertNull( Readiness::tested_up_to( 'no-such-plugin/no-such-plugin.php' ) );
		$this->assertNull( Readiness::tested_up_to( '../wp-config.php' ) );
	}
}
