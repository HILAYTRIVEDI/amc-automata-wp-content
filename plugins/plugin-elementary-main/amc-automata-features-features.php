<?php
/**
 * AMC Automata Features Features
 *
 * @package           AMC_Automata_Features\Features
 * @author            rtCamp
 * @copyright         2026 rtCamp
 * @license           GPL-2.0-or-later
 *
 * Plugin Name:       AMC Automata Features Features
 * Plugin URI:        https://rtcamp.com
 * Description:       All backend functionality: post types, taxonomies, blocks, REST endpoints, settings, shortcodes, and custom roles.
 * x-release-please-start-version
 * Version:           1.0.0
 * x-release-please-end
 * Author:            rtCamp
 * Author URI:        https://rtcamp.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       amc-automata-features-features
 * Domain Path:       /languages
 * Requires PHP:      8.2
 * Requires at least: 6.7
 * Tested up to:      7.1
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Define the plugin constants.
 */
function constants(): void {
	/**
	 * File path to the plugin's main file.
	 */
	define( 'AMC_AUTOMATA_FEATURES_FEATURES_FILE', __FILE__ );

	/**
	 * Version of the plugin.
	 */
	define( 'AMC_AUTOMATA_FEATURES_FEATURES_VERSION', '1.0.0' );

	/**
	 * Root path to the plugin directory.
	 */
	define( 'AMC_AUTOMATA_FEATURES_FEATURES_PATH', plugin_dir_path( AMC_AUTOMATA_FEATURES_FEATURES_FILE ) );

	/**
	 * Root URL to the plugin directory.
	 */
	define( 'AMC_AUTOMATA_FEATURES_FEATURES_URL', plugin_dir_url( AMC_AUTOMATA_FEATURES_FEATURES_FILE ) );

	/**
	 * Whether Tailwind CSS is enabled. Off by default; the scaffold's Tailwind
	 * feature flips this to true on enable. Define it in wp-config.php to force
	 * it either way.
	 */
	if ( ! defined( 'AMC_AUTOMATA_FEATURES_FEATURES_ENABLE_TAILWIND' ) ) {
		define( 'AMC_AUTOMATA_FEATURES_FEATURES_ENABLE_TAILWIND', false );
	}
}

constants();

// If autoloader fails, we cannot proceed.
require_once __DIR__ . '/inc/Autoloader.php';
if ( ! class_exists( Autoloader::class ) || ! Autoloader::autoload() ) {
	return;
}

// Load the main plugin class.
if ( class_exists( Main::class ) ) {
	Main::get_instance();
}
