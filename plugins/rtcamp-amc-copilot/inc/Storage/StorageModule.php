<?php
/**
 * Storage module.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Storage;

use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractModule;

/**
 * Class StorageModule
 *
 * Owner: lane A1. Only A1 edits this file.
 *
 * Add the Retention job here (daily cron, batches of 1,000 via
 * AbstractRepository::delete_older_than(), 20 s budget, Lock::named( 'retention' )).
 *
 * @since 0.1.0
 */
final class StorageModule extends AbstractModule {

	/**
	 * {@inheritDoc}
	 */
	protected function get_classes(): array {
		return [];
	}
}
