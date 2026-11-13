<?php
/**
 * REST API module.
 *
 * Groups all REST controller classes.
 * To add a new endpoint: create it in REST/ and add the class-string here.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Modules;

use AMC_Automata_Features\Features\Modules\REST\ExampleRESTController;
use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractModule;

/**
 * Class - REST
 */
final class REST extends AbstractModule {

	/**
	 * {@inheritDoc}
	 */
	protected function get_classes(): array {
		return [
			ExampleRESTController::class,
		];
	}
}
