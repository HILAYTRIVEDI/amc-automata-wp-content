<?php
/**
 * Tests for the plugin Templates loader.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Tests;

use AMC_Automata_Features\Features\Core\Templates;
use AMC_Automata_Features\Features\Main;

/**
 * Class TemplatesTest
 */
final class TemplatesTest extends TestCase {

	/**
	 * The class exists and extends the framework TemplateLoader.
	 */
	public function test_extends_framework_template_loader(): void {
		$this->assertInstanceOf( 'rtCamp\WPPrimitives\TemplateLoader', new Templates() );
	}

	/**
	 * It is shareable, so the container hands out a single instance.
	 */
	public function test_is_shareable(): void {
		$this->assertInstanceOf( 'rtCamp\WPPrimitives\Contracts\Interfaces\Shareable', new Templates() );
	}

	/**
	 * It is registered in Main and resolvable from the container.
	 */
	public function test_registered_and_shared_in_main(): void {
		$this->assertContains( Templates::class, Main::CLASSES );
		$this->assertInstanceOf( Templates::class, Main::get_instance()->get_shared( Templates::class ) );
	}

	/**
	 * It resolves a plugin-shipped template part by slug.
	 *
	 * Self-contained: writes a throwaway template into the plugin's `templates/`
	 * dir and removes it, so the test does not depend on any example capability
	 * (the example block template is deleted when `blocks` is removed at init).
	 */
	public function test_resolves_a_plugin_template(): void {
		$templates_dir = AMC_AUTOMATA_FEATURES_FEATURES_PATH . 'templates';
		$fixture       = $templates_dir . '/zz-templates-test-fixture.php';

		if ( ! is_dir( $templates_dir ) ) {
			mkdir( $templates_dir, 0755, true );
		}
		file_put_contents( $fixture, "<?php\n" );

		$located = ( new Templates() )->locate( 'zz-templates-test-fixture' );

		unlink( $fixture );

		$this->assertIsString( $located );
		$this->assertStringEndsWith( 'zz-templates-test-fixture.php', (string) $located );
	}

	/**
	 * A missing template resolves to false rather than erroring.
	 */
	public function test_missing_template_returns_false(): void {
		$this->assertFalse( ( new Templates() )->locate( 'no-such-template' ) );
	}
}
