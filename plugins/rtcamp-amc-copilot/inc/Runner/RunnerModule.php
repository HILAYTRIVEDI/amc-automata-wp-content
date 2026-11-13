<?php
/**
 * Runner module.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Runner;

use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractModule;

/**
 * Class RunnerModule
 *
 * Owner: Wave 0.
 *
 * @since 0.1.0
 */
final class RunnerModule extends AbstractModule {

	/**
	 * {@inheritDoc}
	 */
	protected function get_classes(): array {
		return [
			Scheduler::class,
		];
	}
}
