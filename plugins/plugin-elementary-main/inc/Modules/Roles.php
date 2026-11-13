<?php
/**
 * Roles module.
 *
 * Groups all custom user role classes.
 * To add a new role: create it in Roles/ and add the class-string here.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Modules;

use AMC_Automata_Features\Features\Modules\Roles\ExampleUserRole;
use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractModule;

/**
 * Class - Roles
 */
final class Roles extends AbstractModule {

	/**
	 * {@inheritDoc}
	 */
	protected function get_classes(): array {
		return [
			ExampleUserRole::class,
		];
	}
}
