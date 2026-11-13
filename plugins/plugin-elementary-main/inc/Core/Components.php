<?php
/**
 * Plugin component loader.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Core;

use AMC_Automata_Features\Features\Main;
use rtCamp\WPPrimitives\AssetLoader;
use rtCamp\WPPrimitives\ComponentLoader;
use rtCamp\WPPrimitives\Contracts\Interfaces\Shareable;

/**
 * Class Components
 *
 * The plugin's component loader. Its components live in the plugin but can be
 * overridden by the active theme — child theme over parent theme — following the
 * WordPress template hierarchy, which falls out of get_asset_loaders()
 * automatically (self == plugin).
 */
final class Components extends ComponentLoader implements Shareable {

	/**
	 * Context slug used to namespace the plugin's component asset handles.
	 */
	protected function get_context(): string {
		return 'amc-automata-features-features';
	}

	/**
	 * Resolve the plugin's shared asset loader (its Assets instance).
	 *
	 * @return AssetLoader Shared plugin asset loader.
	 */
	protected function get_asset_loader(): AssetLoader {
		/**
		 * Shared plugin asset loader.
		 *
		 * @var Assets $assets
		 */
		$assets = Main::get_instance()->get_shared( Assets::class );

		return $assets;
	}
}
