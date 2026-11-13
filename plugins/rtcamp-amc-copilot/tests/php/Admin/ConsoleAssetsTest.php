<?php
/**
 * Tests for enqueuing the console app.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Admin;

use AMC\Admin\ConsoleAssets;
use AMC\Admin\ConsolePage;
use AMC\Core\Capabilities;
use AMC\Tests\TestCase;
use rtCamp\WPPrimitives\AssetLoader;

/**
 * Class ConsoleAssetsTest
 *
 * Uses a throw-away fake build in the system temp directory, so the tests
 * pass whether or not `npm run build` has been run.
 */
final class ConsoleAssetsTest extends TestCase {

	/**
	 * Fake plugin directory holding assets/build.
	 *
	 * @var string
	 */
	private string $dir = '';

	public function set_up(): void {
		parent::set_up();

		$this->dir = trailingslashit( get_temp_dir() ) . 'amc-console-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->dir . '/assets/build/js' );
		wp_mkdir_p( $this->dir . '/assets/build/css' );

		wp_deregister_script( ConsoleAssets::HANDLE );
		wp_deregister_style( ConsoleAssets::HANDLE );
	}

	public function tear_down(): void {
		wp_deregister_script( ConsoleAssets::HANDLE );
		wp_deregister_style( ConsoleAssets::HANDLE );

		foreach ( [ 'assets/build/js/console.js', 'assets/build/js/console.asset.php', 'assets/build/css/console.css' ] as $file ) {
			if ( file_exists( $this->dir . '/' . $file ) ) {
				unlink( $this->dir . '/' . $file ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink -- Test fixture cleanup.
			}
		}

		parent::tear_down();
	}

	/**
	 * Write a fake build.
	 *
	 * @param bool $with_css Also write the stylesheet.
	 */
	private function build( bool $with_css = true ): AssetLoader {
		file_put_contents( $this->dir . '/assets/build/js/console.js', '/* console */' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $this->dir . '/assets/build/js/console.asset.php', "<?php return array( 'dependencies' => array( 'wp-element', 'wp-api-fetch' ), 'version' => 'abc123' );" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		if ( $with_css ) {
			file_put_contents( $this->dir . '/assets/build/css/console.css', '/* console */' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		}

		return new AssetLoader( $this->dir, 'https://example.org/wp-content/plugins/amc/', 'assets/build' );
	}

	private function engineer(): int {
		$user = self::factory()->user->create( [ 'role' => 'editor' ] );
		Capabilities::grant( $user );
		wp_set_current_user( $user );

		return $user;
	}

	public function test_hooks_into_admin_enqueue_scripts_only(): void {
		$assets = new ConsoleAssets();
		$assets->register_hooks();

		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', [ $assets, 'enqueue' ] ) );
		$this->assertFalse( has_action( 'wp_enqueue_scripts', [ $assets, 'enqueue' ] ), 'Nothing is enqueued on the front end.' );
	}

	public function test_enqueues_script_and_style_on_the_console_page_for_engineers(): void {
		$this->engineer();

		( new ConsoleAssets( $this->build() ) )->enqueue( ConsolePage::hook_suffix() );

		$this->assertTrue( wp_script_is( ConsoleAssets::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( ConsoleAssets::HANDLE, 'enqueued' ) );

		$script = wp_scripts()->registered[ ConsoleAssets::HANDLE ];
		$this->assertContains( 'wp-element', $script->deps, 'Dependencies come from the build manifest.' );
		$this->assertContains( 'wp-api-fetch', $script->deps );
		$this->assertSame( 'abc123', $script->ver );
		$this->assertContains( 'wp-components', wp_styles()->registered[ ConsoleAssets::HANDLE ]->deps );
	}

	public function test_sets_window_amc_copilot_with_nonce_and_mocks_off(): void {
		$this->engineer();

		( new ConsoleAssets( $this->build() ) )->enqueue( ConsolePage::hook_suffix() );

		$before = implode( "\n", (array) wp_scripts()->get_data( ConsoleAssets::HANDLE, 'before' ) );

		$this->assertStringContainsString( 'window.amcCopilot = ', $before );
		$this->assertStringContainsString( '"restNonce":"' . wp_create_nonce( 'wp_rest' ) . '"', $before );
		$this->assertStringContainsString( '"useMocks":false', $before );
	}

	public function test_translations_are_set_for_the_text_domain(): void {
		$this->engineer();

		( new ConsoleAssets( $this->build() ) )->enqueue( ConsolePage::hook_suffix() );

		$this->assertSame( 'rtcamp-amc-copilot', wp_scripts()->registered[ ConsoleAssets::HANDLE ]->textdomain );
	}

	public function test_does_not_enqueue_on_other_admin_screens(): void {
		$this->engineer();

		foreach ( [ 'index.php', 'edit.php', 'toplevel_page_other', 'amc-copilot_page_x' ] as $hook ) {
			( new ConsoleAssets( $this->build() ) )->enqueue( $hook );
		}

		$this->assertFalse( wp_script_is( ConsoleAssets::HANDLE, 'registered' ) );
		$this->assertFalse( wp_style_is( ConsoleAssets::HANDLE, 'registered' ) );
	}

	public function test_does_not_enqueue_for_users_without_amc_manage(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		( new ConsoleAssets( $this->build() ) )->enqueue( ConsolePage::hook_suffix() );

		$this->assertFalse( wp_script_is( ConsoleAssets::HANDLE, 'registered' ) );
	}

	public function test_missing_build_enqueues_nothing_and_raises_no_notice(): void {
		$this->engineer();
		$loader = new AssetLoader( $this->dir, 'https://example.org/', 'assets/build' );

		( new ConsoleAssets( $loader ) )->enqueue( ConsolePage::hook_suffix() );

		$this->assertFalse( wp_script_is( ConsoleAssets::HANDLE, 'registered' ) );
	}

	public function test_works_without_a_stylesheet(): void {
		$this->engineer();

		( new ConsoleAssets( $this->build( false ) ) )->enqueue( ConsolePage::hook_suffix() );

		$this->assertTrue( wp_script_is( ConsoleAssets::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'wp-components', 'enqueued' ) );
	}

	public function test_config_can_be_filtered_for_development(): void {
		add_filter(
			'amc_console_config',
			static fn ( array $config ): array => array_merge( $config, [ 'useMocks' => true ] )
		);

		$config = ( new ConsoleAssets() )->config();

		$this->assertTrue( $config['useMocks'] );
		$this->assertNotSame( '', $config['restNonce'] );
	}
}
