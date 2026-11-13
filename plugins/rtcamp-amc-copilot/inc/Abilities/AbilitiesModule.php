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
 * Register every check (read-only) and report action as a WordPress
 * ability by extending rtCamp AbstractAbilityRegistrar / AbstractAbility.
 *
 * @since 0.1.0
 */
final class AbilitiesModule extends AbstractModule {

	/**
	 * {@inheritDoc}
	 */
	protected function get_classes(): array {
		return [];
	}
}
