<?php
/**
 * Base class for check providers.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks\Providers;

use AMC\Checks\Registry;
use rtCamp\WPPrimitives\Contracts\Interfaces\Registrable;

/**
 * Class AbstractCheckProvider
 *
 * A provider adds a lane's checks to the `amc_register_checks` filter.
 * Subclasses only implement checks().
 *
 * @since 0.1.0
 */
abstract class AbstractCheckProvider implements Registrable {

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_filter( Registry::FILTER, [ $this, 'register' ] );
	}

	/**
	 * Filter callback: append this provider's checks.
	 *
	 * @param mixed $checks Checks so far.
	 *
	 * @return list<mixed>
	 */
	public function register( mixed $checks ): array {
		$checks = is_array( $checks ) ? array_values( $checks ) : [];

		foreach ( $this->checks() as $check ) {
			$checks[] = $check;
		}

		return $checks;
	}

	/**
	 * The checks this provider owns.
	 *
	 * @return list<\AMC\Checks\Check>
	 */
	abstract public function checks(): array;
}
