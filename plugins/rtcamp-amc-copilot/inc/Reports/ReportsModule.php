<?php
/**
 * Reports module.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports;

use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractModule;

/**
 * Class ReportsModule
 *
 * Owner: lane D1. Add Generator, Periods, SavedFields, schedules and report
 * emails here. Section blocks live in Blocks\SectionBlocksModule (D2).
 *
 * @since 0.1.0
 */
final class ReportsModule extends AbstractModule {

	/**
	 * {@inheritDoc}
	 */
	protected function get_classes(): array {
		return [
			ReportPostType::class,
		];
	}
}
