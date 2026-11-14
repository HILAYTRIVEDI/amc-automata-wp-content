<?php
/**
 * Enqueues the console app on its own admin screen.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Admin;

use AMC\Core\Assets;
use AMC\Core\Brand;
use AMC\Core\Capabilities;
use AMC\Main;
use rtCamp\WPPrimitives\AssetLoader;
use rtCamp\WPPrimitives\Contracts\Interfaces\Registrable;

/**
 * Class ConsoleAssets
 *
 * Owner: lane C. The console is built from `src/js/console/index.js` to
 * `assets/build/js/console.js` and `assets/build/css/console.css`
 * (`npm run build:assets`). It is enqueued only on the console page, only for
 * users who hold `amc_manage`, and never on the front end. It sets
 * `window.amcCopilot = { restNonce, useMocks }` before the script runs.
 *
 * @since 0.1.0
 */
final class ConsoleAssets implements Registrable {

	/**
	 * Script and style handle.
	 */
	public const HANDLE = 'rtcamp-amc-copilot-console';

	/**
	 * Asset loader (the plugin's own, unless a test injects one).
	 *
	 * @var AssetLoader|null
	 */
	private ?AssetLoader $loader;

	/**
	 * Constructor.
	 *
	 * @param AssetLoader|null $loader Test seam; defaults to the shared Assets service.
	 */
	public function __construct( ?AssetLoader $loader = null ) {
		$this->loader = $loader;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Whether the console build exists (it is gitignored and built in CI).
	 */
	public static function is_built(): bool {
		return ( new self() )->loader()->has_asset( 'js/console', 'js' );
	}

	/**
	 * Enqueue the console on its own screen.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( ConsolePage::hook_suffix() !== $hook_suffix || ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}

		$loader = $this->loader();

		// ConsolePage tells the engineer the build is missing; do not raise a
		// _doing_it_wrong from the loader on top of that.
		if ( ! $loader->has_asset( 'js/console', 'js' ) ) {
			return;
		}

		$loader->register_script( self::HANDLE, 'js/console' );

		Brand::enqueue_fonts();

		if ( $loader->has_asset( 'css/console', 'css' ) ) {
			$loader->register_style( self::HANDLE, 'css/console', [ 'wp-components' ] );
			wp_enqueue_style( self::HANDLE );
		} else {
			wp_enqueue_style( 'wp-components' );
		}

		wp_set_script_translations( self::HANDLE, 'rtcamp-amc-copilot', AMC_COPILOT_PATH . 'languages' );

		wp_add_inline_script(
			self::HANDLE,
			'window.amcCopilot = ' . wp_json_encode( $this->config() ) . ';',
			'before'
		);

		wp_enqueue_script( self::HANDLE );
	}

	/**
	 * Values handed to the app as `window.amcCopilot`.
	 *
	 * `useMocks` is always false in PHP: the app reads the REST API. A
	 * developer can flip it with the `amc_console_config` filter (for example
	 * from an mu-plugin on a local site) to work on the UI with fixtures.
	 *
	 * @return array{restNonce: string, useMocks: bool}
	 */
	public function config(): array {
		$config = [
			'restNonce' => wp_create_nonce( 'wp_rest' ),
			'useMocks'  => false,
		];

		/**
		 * Filters the console's `window.amcCopilot` settings.
		 *
		 * @since 0.1.0
		 *
		 * @param array{restNonce: string, useMocks: bool} $config Settings.
		 */
		$filtered = apply_filters( 'amc_console_config', $config );

		return [
			'restNonce' => (string) ( $filtered['restNonce'] ?? $config['restNonce'] ),
			'useMocks'  => (bool) ( $filtered['useMocks'] ?? false ),
		];
	}

	/**
	 * The asset loader.
	 */
	private function loader(): AssetLoader {
		if ( null === $this->loader ) {
			/**
			 * Shared Assets service.
			 *
			 * @var Assets $assets
			 */
			$assets       = Main::get_instance()->get_shared( Assets::class );
			$this->loader = $assets;
		}

		return $this->loader;
	}
}
