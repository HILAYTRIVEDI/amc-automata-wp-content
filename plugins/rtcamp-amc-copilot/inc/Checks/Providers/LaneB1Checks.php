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
 * @since 0.1.0
 */
final class LaneB1Checks extends AbstractCheckProvider {

	/**
	 * {@inheritDoc}
	 */
	public function checks(): array {
		return [
			new \AMC\Checks\Updates\PluginUpdates(),
			new \AMC\Checks\Updates\ThemeUpdates(),
			new \AMC\Checks\Updates\PhpUpdates(),
			new \AMC\Checks\Updates\Readiness(),
			new \AMC\Checks\Inventory\PluginInventory(),
			new \AMC\Checks\Inventory\ThemeInventory(),
			new \AMC\Checks\Inventory\MuDropins(),
			new \AMC\Checks\Inventory\Editor(),
			new \AMC\Checks\Detection\CdnDetect(),
			new \AMC\Checks\Detection\AnalyticsDetect(),
			new \AMC\Checks\Detection\FormsDetect(),
			new \AMC\Checks\Detection\MailerDetect(),
			new \AMC\Checks\Detection\AccessibilityDetect(),
			new \AMC\Checks\Hosting\Platform(),
			new \AMC\Checks\Users\Roles(),
			new \AMC\Checks\Users\UserChanges(),
			new \AMC\Checks\Health\SiteHealth(),
			new \AMC\Checks\Health\CronHealth(),
			new \AMC\Checks\Performance\CacheCheck(),
			new \AMC\Checks\Performance\DatabaseCheck(),
		];
	}
}
