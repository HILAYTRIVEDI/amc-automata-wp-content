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
 * Performance budget: no DB queries during the request; events are buffered in
 * memory (at most 200 distinct entries) and written ONCE in `shutdown` through
 * EventsRepository::upsert_many() / QueriesRepository::upsert_many().
 * KillSwitch::is_active() is read lazily at flush time; the
 * AMC_COPILOT_DISABLED constant also stops the hooks from being added.
 *
 * @since 0.1.0
 */
final class CaptureModule extends AbstractModule {

	/**
	 * {@inheritDoc}
	 */
	protected function get_classes(): array {
		return [
			ErrorCapture::class,
			DeprecationCapture::class,
			QuerySampler::class,
			ChangeLog::class,
		];
	}
}
