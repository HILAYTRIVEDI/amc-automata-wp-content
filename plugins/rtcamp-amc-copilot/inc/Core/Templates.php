<?php
/**
 * Handles efficient plugin template loading (and overriding from themes).
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Core;

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
	private const HOOK_PREFIX = 'amc';

	/**
	 * Template directory, relative to the plugin root.
	 */
	private const TEMPLATE_DIR = 'templates';

	/**
	 * Directory name themes override templates into: theme/rtcamp-amc-copilot/.
	 */
	private const TEMPLATE_THEME_DIR = 'rtcamp-amc-copilot';

	/**
	 * Configure the loader with the plugin's template paths.
	 */
	public function __construct() {
		parent::__construct(
			self::HOOK_PREFIX,
			AMC_COPILOT_PATH . self::TEMPLATE_DIR,
			self::TEMPLATE_THEME_DIR
		);
	}
}
