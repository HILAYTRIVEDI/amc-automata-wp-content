<?php
/**
 * Enqueue plugin assets: styles, scripts, and blocks.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Core;

use rtCamp\WPPrimitives\AssetLoader;
use rtCamp\WPPrimitives\Contracts\Interfaces\Registrable;
use rtCamp\WPPrimitives\Contracts\Interfaces\Shareable;

/**
 * Class - Assets
 *
 * Extends the framework's AssetLoader to register the plugin's own assets.
 */
final class Assets extends AssetLoader implements Registrable, Shareable {

	/**
	 * Asset handle prefix, namespacing this plugin's handles. Overrides the
	 * framework default; read by AssetLoader::handle().
	 */
	public const HANDLE_PREFIX = 'amc-automata-features-features-';

	/**
	 * Static blocks to register (build dir names, no render_callback needed).
	 *
	 * Dynamic blocks are registered via their own AbstractBlock subclasses.
	 */
	private const STATIC_BLOCKS = [
		'example-block',
		'example-block-interactive',
	];

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			(string) AMC_AUTOMATA_FEATURES_FEATURES_PATH,
			(string) AMC_AUTOMATA_FEATURES_FEATURES_URL,
			'assets/build'
		);
	}

	/**
	 * Whether Tailwind CSS is enabled. Resolved at enqueue time (not in the
	 * constructor) so the amc_automata_features_features_tailwind_enabled filter can be
	 * added by themes/plugins that load after this one.
	 */
	private function is_tailwind_enabled(): bool {
		return (bool) apply_filters( 'amc_automata_features_features_tailwind_enabled', AMC_AUTOMATA_FEATURES_FEATURES_ENABLE_TAILWIND );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'init', [ $this, 'register_blocks' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'register_admin_assets' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'register_editor_assets' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'register_module_scripts' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_browser_sync' ] );
	}

	/**
	 * Enqueue the BrowserSync client script for local live reload.
	 *
	 * Only runs in the `local` environment, while HMR is on (ENABLE_HMR in
	 * .env.local, the master switch honoured by webpack and PHP alike) and not
	 * disabled via DISABLE_BS. The client URL is derived from the site URL and the
	 * BrowserSync port (BS_PORT in .env.local, default 3003), or taken verbatim
	 * from the AMC_AUTOMATA_FEATURES_FEATURES_BROWSER_SYNC_URL constant when defined (for
	 * custom ports or remote/proxied setups).
	 */
	public function enqueue_browser_sync(): void {
		if ( 'local' !== wp_get_environment_type() || ! $this->is_hmr_enabled() || $this->is_browser_sync_disabled() ) {
			return;
		}

		if ( defined( 'AMC_AUTOMATA_FEATURES_FEATURES_BROWSER_SYNC_URL' ) ) {
			$bs_url = AMC_AUTOMATA_FEATURES_FEATURES_BROWSER_SYNC_URL;
		} else {
			$scheme = is_ssl() ? 'https' : 'http';
			$host   = wp_parse_url( home_url(), PHP_URL_HOST );
			$host   = $host ? $host : 'localhost';
			$port   = $this->get_browser_sync_port();
			$bs_url = "{$scheme}://{$host}:{$port}/browser-sync/browser-sync-client.js";
		}

		wp_enqueue_script( $this->handle( 'browser-sync' ), $bs_url, [], AMC_AUTOMATA_FEATURES_FEATURES_VERSION, true );
	}

	/**
	 * Read the BrowserSync port from .env.local (BS_PORT), defaulting to 3003.
	 *
	 * Keeps the enqueued client URL in sync with the port webpack/BrowserSync
	 * actually bind to, which is read from the same .env.local on the build side.
	 * Falls back to the default when BS_PORT is absent or not a valid TCP port
	 * (1–65535).
	 *
	 * THIS METHOD IS INTENDED FOR LOCAL DEVELOPMENT ENVIRONMENTS ONLY.
	 *
	 * @return int BrowserSync port.
	 */
	private function get_browser_sync_port(): int {
		$default = 3003;
		$value   = $this->get_env_value( 'BS_PORT' );

		if ( null !== $value && preg_match( '/^\d+$/', $value ) ) {
			$port = (int) $value;

			if ( $port >= 1 && $port <= 65535 ) {
				return $port;
			}
		}

		return $default;
	}

	/**
	 * Whether HMR (BrowserSync live reload) is enabled via ENABLE_HMR in .env.local.
	 *
	 * Master switch for both sides: webpack only starts the BrowserSync server,
	 * and PHP only enqueues its client, when this is on. Defaults ON when the key
	 * is absent. Off values are `0`, `false`, `no`, and `off` (case-insensitive).
	 * DISABLE_BS still works as a finer client-only override. Toggle it from
	 * `npm run init` (manage mode) or by editing .env.local directly.
	 *
	 * THIS METHOD IS INTENDED FOR LOCAL DEVELOPMENT ENVIRONMENTS ONLY.
	 *
	 * @return bool True when HMR is enabled.
	 */
	private function is_hmr_enabled(): bool {
		$value = $this->get_env_value( 'ENABLE_HMR' );

		if ( null === $value ) {
			return true;
		}

		return ! in_array( strtolower( $value ), [ '0', 'false', 'no', 'off' ], true );
	}

	/**
	 * Whether BrowserSync is disabled via DISABLE_BS in .env.local.
	 *
	 * Disabling prevents PHP from enqueuing the BrowserSync client script. The
	 * BrowserSync server still starts (webpack still runs it), but the browser
	 * won't connect to it. Truthy values are `1`, `true`, `yes`, and `on`
	 * (case-insensitive); anything else (or an absent key) keeps it enabled.
	 *
	 * THIS METHOD IS INTENDED FOR LOCAL DEVELOPMENT ENVIRONMENTS ONLY.
	 *
	 * @return bool True when BrowserSync should be disabled.
	 */
	private function is_browser_sync_disabled(): bool {
		$value = $this->get_env_value( 'DISABLE_BS' );

		if ( null === $value ) {
			return false;
		}

		return in_array( strtolower( $value ), [ '1', 'true', 'yes', 'on' ], true );
	}

	/**
	 * Read a single key's value from .env.local.
	 *
	 * Returns the trimmed value (without surrounding single/double quotes) for
	 * the given key, or null when the file is unreadable or the key is absent.
	 *
	 * THIS METHOD IS INTENDED FOR LOCAL DEVELOPMENT ENVIRONMENTS ONLY.
	 *
	 * @param string $key Environment variable name to read.
	 *
	 * @return string|null The value, or null when not found.
	 */
	private function get_env_value( string $key ): ?string {
		$env_file = AMC_AUTOMATA_FEATURES_FEATURES_PATH . '.env.local';

		if ( ! is_readable( $env_file ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Local dev only; reading a small project file, not remote.
		$contents = file_get_contents( $env_file );

		if ( false === $contents ) {
			return null;
		}

		if ( preg_match( '/^\s*' . preg_quote( $key, '/' ) . '\s*=\s*(.*)$/m', $contents, $matches ) ) {
			return trim( $matches[1], " \t\"'" );
		}

		return null;
	}

	/**
	 * Register static blocks.
	 *
	 * Dynamic blocks self-register via their AbstractBlock subclass.
	 */
	public function register_blocks(): void {
		$blocks_dir = $this->base_dir . 'assets/build/blocks/';

		foreach ( self::STATIC_BLOCKS as $block ) {
			$dir = $blocks_dir . $block;
			if ( is_dir( $dir ) ) {
				register_block_type( $dir );
			}
		}
	}

	/**
	 * Register assets for the frontend.
	 *
	 * Assets are registered once centrally and enqueued in modules that need them.
	 */
	public function register_assets(): void {
		$this->register_script( $this->handle( 'frontend' ), 'js/main' );
		$this->register_style( $this->handle( 'frontend' ), 'css/main' );

		wp_enqueue_script( $this->handle( 'frontend' ) );
		wp_enqueue_style( $this->handle( 'frontend' ) );

		if ( $this->is_tailwind_enabled() ) {
			$this->register_style( $this->handle( 'tailwind' ), 'css/tailwind' );
			wp_enqueue_style( $this->handle( 'tailwind' ) );
		}
	}

	/**
	 * Register assets for the admin.
	 */
	public function register_admin_assets(): void {
		$this->register_script( $this->handle( 'admin' ), 'js/admin' );
		$this->register_style( $this->handle( 'admin' ), 'css/admin' );
	}

	/**
	 * Register assets for the block editor.
	 *
	 * The editor entry is optional: webpack only emits `js/editor` and
	 * `css/editor` once `src/js/editor.js` / `src/css/editor.scss` exist. Register
	 * only what the build produced, so a project without an editor entry does
	 * not raise an "Asset file is missing" notice on every editor load.
	 */
	public function register_editor_assets(): void {
		if ( $this->has_asset( 'js/editor', 'js' ) ) {
			$this->register_script( $this->handle( 'editor' ), 'js/editor' );
		}

		if ( $this->has_asset( 'css/editor', 'css' ) ) {
			$this->register_style( $this->handle( 'editor' ), 'css/editor' );
		}
	}

	/**
	 * Register interactive block module scripts.
	 */
	public function register_module_scripts(): void {
		if ( $this->register_script_module( '@amc-automata-features-features/module', 'js/modules/module', [ '@wordpress/interactivity' ] ) ) {
			wp_enqueue_script_module( '@amc-automata-features-features/module' );
		}
	}
}
