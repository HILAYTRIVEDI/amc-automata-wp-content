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
 * Alerts listens to `amc_findings_processed` (payload: AMC\Runner\ProcessedFindings)
 * and sends immediate alerts; Digest owns the daily cron event. AlertRules,
 * Throttle, Mailer and EmailRenderer are plain helpers used by both.
 *
 * @since 0.1.0
 */
final class NotifyModule extends AbstractModule {

	/**
	 * {@inheritDoc}
	 */
	protected function get_classes(): array {
		return [
			Alerts::class,
			Digest::class,
		];
	}
}
