<?php
/**
 * REST module: every `amc/v1` controller.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Rest;

use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractModule;

/**
 * Class RestModule
 *
 * FROZEN (Wave 0): the full route map is registered here. Lanes edit the
 * handler bodies in the controller they own, never this list. Route map
 * and owners: docs/CONTRACTS.md § REST.
 *
 * @since 0.1.0
 */
final class RestModule extends AbstractModule {

	/**
	 * {@inheritDoc}
	 */
	protected function get_classes(): array {
		return [
			// Wave 0.
			OverviewController::class,
			ChecksController::class,
			RunsController::class,
			FindingsController::class,
			SettingsController::class,
			AccessController::class,
			// B1.
			UpdatesController::class,
			InventoryController::class,
			// B2.
			VulnerabilitiesController::class,
			SecurityController::class,
			PerformanceController::class,
			SmokeController::class,
			// A1.
			ErrorsController::class,
			ChangesController::class,
			// A2.
			NotifyController::class,
			// D1.
			ReportsController::class,
			// C (setup wizard).
			SetupController::class,
		];
	}
}
