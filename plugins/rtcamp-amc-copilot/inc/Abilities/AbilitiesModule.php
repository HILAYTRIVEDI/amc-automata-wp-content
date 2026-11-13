<?php
/**
 * Abilities module.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Abilities;

use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractModule;

/**
 * Class AbilitiesModule
 *
 * Owner: lane B2. Only B2 edits this file.
 *
 * Registers every check (read-only) and the report read actions as
 * WordPress abilities (see AbilitiesRegistrar). WordPress 6.9+; on older
 * cores the Abilities API hooks never fire, so nothing is registered.
 *
 * @since 0.1.0
 */
final class AbilitiesModule extends AbstractModule {

	/**
	 * {@inheritDoc}
	 */
	protected function get_classes(): array {
		return [
			AbilitiesRegistrar::class,
		];
	}
}
