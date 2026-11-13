<?php
/**
 * Cron module.
 *
 * Groups all WP-Cron job classes.
 * To add a new cron job: create it in Cron/ and add the class-string here.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Modules;

use AMC_Automata_Features\Features\Modules\Cron\ExampleCronJob;
use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractModule;

/**
 * Class - Cron
 */
final class Cron extends AbstractModule {

	/**
	 * {@inheritDoc}
	 */
	protected function get_classes(): array {
		return [
			ExampleCronJob::class,
		];
	}
}
