<?php
/**
 * Settings module.
 *
 * Groups all admin settings page classes.
 * To add a new settings page: create it in Settings/ and add the class-string here.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Modules;

use AMC_Automata_Features\Features\Modules\Settings\ExampleSettingsPage;
use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractModule;

/**
 * Class - Settings
 */
final class Settings extends AbstractModule {

	/**
	 * {@inheritDoc}
	 */
	protected function get_classes(): array {
		return [
			ExampleSettingsPage::class,
		];
	}
}
