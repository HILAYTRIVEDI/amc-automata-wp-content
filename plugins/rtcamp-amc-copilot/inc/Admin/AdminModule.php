<?php
/**
 * Admin module (console).
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Admin;

use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractModule;

/**
 * Class AdminModule
 *
 * Owner: lane C. Only C edits this file and inc/Admin/*.
 *
 * @since 0.1.0
 */
final class AdminModule extends AbstractModule {

	/**
	 * {@inheritDoc}
	 */
	protected function get_classes(): array {
		return [
			ConsolePage::class,
			ConsoleAssets::class,
			DashboardWidget::class,
		];
	}
}
