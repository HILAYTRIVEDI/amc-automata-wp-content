<?php
/**
 * Shortcodes module.
 *
 * Groups all shortcode classes.
 * To add a new shortcode: create it in Shortcodes/ and add the class-string here.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Modules;

use AMC_Automata_Features\Features\Modules\Shortcodes\ExampleShortcode;
use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractModule;

/**
 * Class - Shortcodes
 */
final class Shortcodes extends AbstractModule {

	/**
	 * {@inheritDoc}
	 */
	protected function get_classes(): array {
		return [
			ExampleShortcode::class,
		];
	}
}
