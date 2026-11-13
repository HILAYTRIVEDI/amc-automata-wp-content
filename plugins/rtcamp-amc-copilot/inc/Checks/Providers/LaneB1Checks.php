<?php
/**
 * Lane B1 checks.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks\Providers;

/**
 * Class LaneB1Checks
 *
 * Owner: lane B1. Only B1 edits this file.
 *
 * Add one line per check, e.g. `new \AMC\Checks\Updates\PluginUpdates(),`
 * Check ids owned by B1: updates.plugins, updates.themes, updates.php,
 * updates.readiness, inventory.plugins, inventory.themes,
 * inventory.mu_dropins, inventory.editor, detect.cdn, detect.forms,
 * detect.mailer, detect.analytics, detect.accessibility, hosting.platform,
 * users.roles, users.changes, health.site_health, health.cron, perf.cache,
 * perf.database.
 *
 * @since 0.1.0
 */
final class LaneB1Checks extends AbstractCheckProvider {

	/**
	 * {@inheritDoc}
	 */
	public function checks(): array {
		return [];
	}
}
