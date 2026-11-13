<?php
/**
 * Plugin setup: activation, deactivation, and textdomain.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Core;

use AMC_Automata_Features\Features\Helpers\Util;
use AMC_Automata_Features\Features\Modules\Cron\ExampleCronJob;
use rtCamp\WPPrimitives\Contracts\Interfaces\Registrable;

/**
 * Class PluginSetup
 */
class PluginSetup implements Registrable {

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		register_activation_hook( AMC_AUTOMATA_FEATURES_FEATURES_FILE, [ $this, 'activate' ] );
		register_deactivation_hook( AMC_AUTOMATA_FEATURES_FEATURES_FILE, [ $this, 'deactivate' ] );

		add_action( 'init', [ $this, 'load_textdomain' ] );
	}

	/**
	 * Runs on successful plugin activation.
	 */
	public function activate(): void {
		update_option( 'amc_automata_features_features_version', AMC_AUTOMATA_FEATURES_FEATURES_VERSION );

		// The shared Logger is available anywhere via Util::logger() (silent unless WP_DEBUG).
		Util::logger()->info( 'Plugin activated', [ 'version' => AMC_AUTOMATA_FEATURES_FEATURES_VERSION ] );
	}

	/**
	 * Runs on successful plugin deactivation.
	 *
	 * For uninstall-time cleanup (option/table removal), use the root
	 * `uninstall.php` file instead — that runs only on actual uninstall.
	 */
	public function deactivate(): void {
		ExampleCronJob::unschedule();
	}

	/**
	 * Load plugin textdomain.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'amc-automata-features-features',
			false,
			dirname( plugin_basename( AMC_AUTOMATA_FEATURES_FEATURES_FILE ) ) . '/languages'
		);
	}
}
