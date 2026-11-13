<?php
/**
 * Capture module.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Capture;

use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractModule;

/**
 * Class CaptureModule
 *
 * Owner: lane A1. Only A1 edits this file.
 *
 * Add ErrorCapture, DeprecationCapture, QuerySampler and ChangeLog here.
 * Performance budget: no DB queries during the request; buffer in memory
 * (max 200 events) and write ONCE in `shutdown` through
 * EventsRepository::upsert_many() / QueriesRepository::upsert_many().
 * Check KillSwitch::is_active() lazily at flush time.
 *
 * @since 0.1.0
 */
final class CaptureModule extends AbstractModule {

	/**
	 * {@inheritDoc}
	 */
	protected function get_classes(): array {
		return [];
	}
}
