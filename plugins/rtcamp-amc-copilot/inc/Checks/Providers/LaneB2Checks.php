<?php
/**
 * Lane B2 checks.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks\Providers;

use AMC\Checks\Errors\Deprecations;
use AMC\Checks\Errors\FatalErrors;
use AMC\Checks\Errors\Warnings;
use AMC\Checks\Performance\ResponseTime;
use AMC\Checks\Performance\SlowQueries;
use AMC\Checks\Security\AdminSnapshot;
use AMC\Checks\Security\ConfigCheck;
use AMC\Checks\Security\CoreChecksums;
use AMC\Checks\Security\SslCertificate;
use AMC\Checks\Smoke\SmokeUrls;
use AMC\Checks\Vulnerabilities\VulnMatchCheck;
use AMC\Vulnerability\FixtureProvider;

/**
 * Class LaneB2Checks
 *
 * Owner: lane B2. Only B2 edits this file.
 *
 * Check ids owned by B2: vuln.match, errors.fatal, errors.warnings,
 * errors.deprecations, smoke.urls, security.checksums, security.config,
 * security.admins, ssl.certificate, perf.response, perf.slow_queries.
 *
 * Also hooks the FixtureProvider (a CVE provider for tests and demos that
 * does nothing unless AMC_COPILOT_FIXTURE_PROVIDER or the
 * `amc_vulnerability_use_fixture` filter turns it on).
 *
 * @since 0.1.0
 */
final class LaneB2Checks extends AbstractCheckProvider {

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		parent::register_hooks();

		( new FixtureProvider() )->register_hooks();
	}

	/**
	 * {@inheritDoc}
	 */
	public function checks(): array {
		return [
			new VulnMatchCheck(),
			new FatalErrors(),
			new Warnings(),
			new Deprecations(),
			new SmokeUrls(),
			new CoreChecksums(),
			new ConfigCheck(),
			new AdminSnapshot(),
			new SslCertificate(),
			new ResponseTime(),
			new SlowQueries(),
		];
	}
}
