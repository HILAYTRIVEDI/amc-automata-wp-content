<?php
/**
 * Lane B2 checks.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks\Providers;

/**
 * Class LaneB2Checks
 *
 * Owner: lane B2. Only B2 edits this file.
 *
 * Check ids owned by B2: vuln.match, errors.fatal, errors.warnings,
 * errors.deprecations, smoke.urls, security.checksums, security.config,
 * security.admins, ssl.certificate, perf.response, perf.slow_queries.
 *
 * @since 0.1.0
 */
final class LaneB2Checks extends AbstractCheckProvider {

	/**
	 * {@inheritDoc}
	 */
	public function checks(): array {
		return [];
	}
}
