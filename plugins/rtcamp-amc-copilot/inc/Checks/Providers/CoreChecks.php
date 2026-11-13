<?php
/**
 * Wave 0 checks.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks\Providers;

use AMC\Checks\Updates\CoreUpdates;

/**
 * Class CoreChecks
 *
 * Owner: Wave 0. Holds the exemplar check only.
 *
 * @since 0.1.0
 */
final class CoreChecks extends AbstractCheckProvider {

	/**
	 * {@inheritDoc}
	 */
	public function checks(): array {
		return [
			new CoreUpdates(),
		];
	}
}
