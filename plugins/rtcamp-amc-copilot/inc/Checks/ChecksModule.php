<?php
/**
 * Checks module: loads the check providers.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks;

use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractModule;

/**
 * Class ChecksModule
 *
 * FROZEN (Wave 0). Each lane registers its checks in its OWN provider file,
 * so lanes never edit the same file:
 *
 *   Providers\CoreChecks   Wave 0 (updates.core)
 *   Providers\LaneB1Checks B1 (simple checks)
 *   Providers\LaneB2Checks B2 (vuln, errors, smoke, security, ssl, perf.response, perf.slow_queries)
 *
 * @since 0.1.0
 */
final class ChecksModule extends AbstractModule {

	/**
	 * {@inheritDoc}
	 */
	protected function get_classes(): array {
		return [
			Providers\CoreChecks::class,
			Providers\LaneB1Checks::class,
			Providers\LaneB2Checks::class,
		];
	}
}
