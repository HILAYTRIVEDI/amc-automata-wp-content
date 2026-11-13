<?php
/**
 * Taxonomies module.
 *
 * Groups all custom taxonomy classes so Main is decoupled from individual registrations.
 * To add a new taxonomy: create it in Taxonomies/ and add the class-string here.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Modules;

use AMC_Automata_Features\Features\Modules\Taxonomies\ExampleTaxonomy;
use AMC_Automata_Features\Features\Modules\Taxonomies\ExampleTaxonomyTwo;
use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractModule;

/**
 * Class - Taxonomies
 */
final class Taxonomies extends AbstractModule {

	/**
	 * {@inheritDoc}
	 */
	protected function get_classes(): array {
		return [
			ExampleTaxonomy::class,
			ExampleTaxonomyTwo::class,
		];
	}
}
