<?php
/**
 * Core module: settings registration and schema upgrades.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Core;

use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractModule;

/**
 * Class CoreModule
 *
 * Owner: Wave 0.
 *
 * @since 0.1.0
 */
final class CoreModule extends AbstractModule {

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		parent::register_hooks();

		// Schema upgrades run in admin only (and in the cron tick / CLI), never on the front end.
		add_action( 'admin_init', [ Installer::class, 'maybe_upgrade' ] );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_classes(): array {
		return [
			Settings::class,
		];
	}
}
