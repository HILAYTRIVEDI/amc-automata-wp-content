<?php
/**
 * Handles efficient plugin template loading (and overriding from themes).
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Core;

use rtCamp\WPPrimitives\Contracts\Interfaces\Shareable;
use rtCamp\WPPrimitives\TemplateLoader;

/**
 * Class - Templates
 *
 * The plugin's template loader: ships template parts the active theme can
 * override (child theme > parent theme > plugin). Extends the framework
 * TemplateLoader and is shared through the container, mirroring the Assets
 * loader. Access it from anywhere via Helpers\Util::templates(), e.g.
 * Util::templates()->render( $slug ) or Util::templates()->get( $slug ).
 */
final class Templates extends TemplateLoader implements Shareable {

	/**
	 * Hook prefix for all of this loader's filters and actions.
	 */
	private const HOOK_PREFIX = 'amc_automata_features_features';

	/**
	 * Template directory, relative to the plugin root.
	 */
	private const TEMPLATE_DIR = 'templates';

	/**
	 * Directory name themes override templates into: theme/amc-automata-features-features/.
	 */
	private const TEMPLATE_THEME_DIR = 'amc-automata-features-features';

	/**
	 * Configure the loader with the plugin's template paths.
	 */
	public function __construct() {
		parent::__construct(
			self::HOOK_PREFIX,
			AMC_AUTOMATA_FEATURES_FEATURES_PATH . self::TEMPLATE_DIR,
			self::TEMPLATE_THEME_DIR
		);
	}
}
