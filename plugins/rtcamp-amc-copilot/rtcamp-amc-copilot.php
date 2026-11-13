<?php
/**
 * AMC Copilot
 *
 * @package           AMC
 * @author            rtCamp
 * @copyright         2026 rtCamp
 * @license           GPL-2.0-or-later
 *
 * Plugin Name:       AMC Copilot
 * Plugin URI:        https://rtcamp.com
 * Description:       Every recurring AMC maintenance check on one wp-admin screen, email alerts, and generated Website Maintenance Reports.
 * x-release-please-start-version
 * Version:           0.1.0
 * x-release-please-end
 * Author:            rtCamp
 * Author URI:        https://rtcamp.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rtcamp-amc-copilot
 * Domain Path:       /languages
 * Requires PHP:      8.1
 * Requires at least: 6.9
 * Tested up to:      7.1
 */

declare( strict_types = 1 );

namespace AMC;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Minimum PHP version. Keep in sync with the `Requires PHP` header.
 */
const MIN_PHP = '8.1';

/**
 * Minimum WordPress version. Keep in sync with the `Requires at least` header.
 */
const MIN_WP = '6.9';

/**
 * Define the plugin constants.
 *
 * `AMC_COPILOT_DISABLED` (the kill switch) is NOT defined here: site owners
 * define it in wp-config.php. See AMC\Core\KillSwitch.
 */
function constants(): void {
	/**
	 * File path to the plugin's main file.
	 */
	define( 'AMC_COPILOT_FILE', __FILE__ );

	/**
	 * Version of the plugin.
	 */
	define( 'AMC_COPILOT_VERSION', '0.1.0' );

	/**
	 * Root path to the plugin directory.
	 */
	define( 'AMC_COPILOT_PATH', plugin_dir_path( AMC_COPILOT_FILE ) );

	/**
	 * Root URL to the plugin directory.
	 */
	define( 'AMC_COPILOT_URL', plugin_dir_url( AMC_COPILOT_FILE ) );
}

/**
 * Whether the running PHP and WordPress versions meet the plugin's minimums.
 *
 * WordPress already refuses to activate a plugin whose headers are not met,
 * but a plugin can still be loaded on an older stack (mu-plugin loader,
 * downgraded core), so we check again at runtime and show a notice instead
 * of fataling.
 */
function requirements_met(): bool {
	global $wp_version;

	return version_compare( PHP_VERSION, MIN_PHP, '>=' )
		&& version_compare( (string) $wp_version, MIN_WP, '>=' );
}

constants();

if ( ! requirements_met() ) {
	add_action(
		'admin_notices',
		static function (): void {
			wp_admin_notice(
				esc_html(
					sprintf(
						/* translators: 1: Minimum PHP version, 2: Minimum WordPress version. */
						__( 'AMC Copilot needs PHP %1$s and WordPress %2$s or newer. It has not been loaded.', 'rtcamp-amc-copilot' ),
						MIN_PHP,
						MIN_WP
					)
				),
				[
					'type'    => 'error',
					'dismiss' => false,
				]
			);
		}
	);
	return;
}

// If autoloader fails, we cannot proceed.
require_once __DIR__ . '/inc/Autoloader.php';
if ( ! class_exists( Autoloader::class ) || ! Autoloader::autoload() ) {
	return;
}

// Load the main plugin class.
if ( class_exists( Main::class ) ) {
	Main::get_instance();
}
