<?php
/**
 * Plugin logger service.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Core;

use rtCamp\WPPrimitives\Contracts\Interfaces\Shareable;
use rtCamp\WPPrimitives\Utils\Logger as FrameworkLogger;

/**
 * Class - Logger
 *
 * The plugin's shared Logger. Extends the framework Logger, prefixed with the
 * plugin slug, and shared through the container so every part of the plugin
 * writes through one instance (like a singleton, but via the framework
 * Shareable contract). Stays silent unless WP_DEBUG. Access it from anywhere
 * via Helpers\Util::logger().
 */
final class Logger extends FrameworkLogger implements Shareable {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( 'amc_automata_features_features' );
	}
}
