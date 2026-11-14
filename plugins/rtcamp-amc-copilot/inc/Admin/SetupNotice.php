<?php
/**
 * "Set up AMC Copilot" admin notice.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Admin;

use AMC\Core\Assets;
use AMC\Main;
use rtCamp\WPPrimitives\AssetLoader;
use rtCamp\WPPrimitives\Contracts\Interfaces\Registrable;

/**
 * Class SetupNotice
 *
 * While the setup wizard has not been finished, shows a dismissible core
 * `notice notice-info` on the Plugins screen and the console, linking to the
 * wizard. Only users who can run the wizard see it, never on the wizard
 * screen itself, and not after the user dismissed it.
 *
 * Dismissal is stored per user through `POST /amc/v1/setup/dismiss`, sent by
 * the small `js/setup-notice` script when the core close button is clicked.
 *
 * @since 0.1.0
 */
final class SetupNotice implements Registrable {

	/**
	 * Script handle.
	 */
	public const HANDLE = 'rtcamp-amc-copilot-setup-notice';

	/**
	 * Class on the notice (the script listens for its close button).
	 */
	public const CSS_CLASS = 'amc-setup-notice';

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
		add_action( 'admin_notices', [ $this, 'render' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Whether the notice shows on a screen.
	 *
	 * @param string $screen_id Current screen ID (`plugins`, `toplevel_page_amc-copilot`, …).
	 */
	public function should_show( string $screen_id ): bool {
		if ( ! in_array( $screen_id, [ 'plugins', ConsolePage::hook_suffix() ], true ) ) {
			return false;
		}

		if ( ConsolePage::hook_suffix() === $screen_id && SetupState::SCREEN === self::requested_screen() ) {
			return false;
		}

		return SetupState::can_run() && ! SetupState::is_completed() && ! SetupState::is_dismissed();
	}

	/**
	 * `admin_notices`: print the notice.
	 */
	public function render(): void {
		if ( ! $this->should_show( self::current_screen_id() ) ) {
			return;
		}

		printf(
			'<div class="notice notice-info is-dismissible %1$s"><p><strong>%2$s</strong> %3$s</p><p><a class="button button-primary" href="%4$s">%5$s</a></p></div>',
			esc_attr( self::CSS_CLASS ),
			esc_html__( 'AMC Copilot is not set up yet.', 'rtcamp-amc-copilot' ),
			esc_html__( 'Answer a few questions about this site so checks, alerts and reports start with the right details.', 'rtcamp-amc-copilot' ),
			esc_url( ConsolePage::url( SetupState::SCREEN ) ),
			esc_html__( 'Run the setup wizard', 'rtcamp-amc-copilot' )
		);
	}

	/**
	 * `admin_enqueue_scripts`: the dismiss script, only where the notice shows.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue( string $hook_suffix ): void {
		$screen_id = 'plugins.php' === $hook_suffix ? 'plugins' : $hook_suffix;

		if ( ! $this->should_show( $screen_id ) ) {
			return;
		}

		$loader = $this->loader();

		// Without the build the notice still shows; closing it lasts until the next page load.
		if ( ! $loader->has_asset( 'js/setup-notice', 'js' ) ) {
			return;
		}

		$loader->register_script( self::HANDLE, 'js/setup-notice' );
		wp_enqueue_script( self::HANDLE );
	}

	/**
	 * The `screen` query arg of the console ('' when absent).
	 */
	private static function requested_screen(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing, as the console itself does.
		return isset( $_GET['screen'] ) && is_string( $_GET['screen'] ) ? sanitize_key( wp_unslash( $_GET['screen'] ) ) : '';
	}

	/**
	 * Current admin screen ID, or ''.
	 */
	private static function current_screen_id(): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen instanceof \WP_Screen ? (string) $screen->id : '';
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
