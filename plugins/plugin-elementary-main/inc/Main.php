<?php
/**
 * Plugin bootstrap file.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features;

use rtCamp\WPPrimitives\Contracts\Traits\Loader;
use rtCamp\WPPrimitives\Contracts\Traits\Singleton;

/**
 * Class - Main
 */
final class Main {

	use Singleton;
	use Loader;

	/*
	 * List of classes to load
	 */
	public const CLASSES = [
		// Core infrastructure - always loaded.
		Core\Assets::class,
		Core\PluginSetup::class,
		Core\Components::class,
		Core\Templates::class,
		Core\Encryption::class,
		Core\Logger::class,

		// Optional capabilities - each is keep/remove at init time. Per-capability
		// init markers (stripped on keep) let the engine drop a capability's line
		// from this list when it is deselected.
		Modules\CLI::class,
		Modules\PostTypes::class,
		Modules\Taxonomies::class,
		Modules\Blocks::class,
		Modules\REST::class,
		Modules\Settings::class,
		Modules\Shortcodes::class,
		Modules\Roles::class,
		Modules\Admin::class,
		Modules\Cron::class,

		// Framework service usage examples.
		Modules\Cache::class,
		Modules\Transients::class,
	];

	/**
	 * Constructor.
	 */
	protected function __construct() {
		$this->load( self::CLASSES );
	}
}
