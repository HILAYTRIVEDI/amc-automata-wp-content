<?php
/**
 * Plugin setup: activation, deactivation, and textdomain.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Core;

use AMC\Helpers\Util;
use AMC\Runner\Scheduler;
use rtCamp\WPPrimitives\Contracts\Interfaces\Registrable;

/**
 * Class PluginSetup
 *
 * Activation: tables + options (Installer), capabilities, cron tick.
 * Deactivation: cron events removed; data kept.
 * Uninstall: see uninstall.php (tables, options, capabilities).
 *
 * Other lanes that schedule their own cron events unschedule them on the
 * `amc_deactivate` action instead of editing this file.
 *
 * @since 0.1.0
 */
class PluginSetup implements Registrable {

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		register_activation_hook( AMC_COPILOT_FILE, [ $this, 'activate' ] );
		register_deactivation_hook( AMC_COPILOT_FILE, [ $this, 'deactivate' ] );

		add_action( 'init', [ $this, 'load_textdomain' ] );
	}

	/**
	 * Runs on successful plugin activation.
	 */
	public function activate(): void {
		Installer::install();
		Capabilities::on_activation();
		Scheduler::schedule();

		update_option( 'amc_version', AMC_COPILOT_VERSION, false );

		/**
		 * Fires after AMC Copilot is activated (lanes schedule their cron events here).
		 */
		do_action( 'amc_activate' );

		Util::logger()->info( 'Plugin activated', [ 'version' => AMC_COPILOT_VERSION ] );
	}

	/**
	 * Runs on successful plugin deactivation. Data is kept.
	 */
	public function deactivate(): void {
		Scheduler::unschedule();

		/**
		 * Fires on deactivation (lanes clear their own cron events here).
		 */
		do_action( 'amc_deactivate' );
	}

	/**
	 * Load plugin textdomain.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'rtcamp-amc-copilot',
			false,
			dirname( plugin_basename( AMC_COPILOT_FILE ) ) . '/languages'
		);
	}
}
