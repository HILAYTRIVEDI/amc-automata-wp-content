<?php
/**
 * Notify module.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Notify;

use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractModule;

/**
 * Class NotifyModule
 *
 * Owner: lane A2. Only A2 edits this file.
 *
 * Add AlertRules, Mailer and Digest here. Listen to the
 * `amc_findings_processed` action (payload: AMC\Runner\ProcessedFindings).
 *
 * @since 0.1.0
 */
final class NotifyModule extends AbstractModule {

	/**
	 * {@inheritDoc}
	 */
	protected function get_classes(): array {
		return [];
	}
}
