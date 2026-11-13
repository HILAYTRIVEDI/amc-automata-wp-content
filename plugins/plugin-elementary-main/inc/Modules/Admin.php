<?php
/**
 * Admin module.
 *
 * Groups custom admin screens built on AbstractAdminPage (plain menu/submenu
 * pages, as distinct from the Settings API pages in Settings/).
 * To add a new admin page: create it in Admin/ and add the class-string here.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Modules;

use AMC_Automata_Features\Features\Modules\Admin\ExampleAdminPage;
use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractModule;

/**
 * Class - Admin
 */
final class Admin extends AbstractModule {

	/**
	 * {@inheritDoc}
	 */
	protected function get_classes(): array {
		return [
			ExampleAdminPage::class,
		];
	}
}
