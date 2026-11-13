<?php
/**
 * Autoloader for PHP classes inside Features Plugin.
 *
 * Provides graceful failure if the Composer autoloader is missing.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features;

/**
 * Class - Autoloader
 */
final class Autoloader {

	/**
	 * Attempts to autoload the Composer dependencies.
	 *
	 * If the autoloader is missing, it will display an admin notice.
	 *
	 * @return bool Whether the autoloader was successfully loaded.
	 */
	public static function autoload(): bool {
		$autoloader = AMC_AUTOMATA_FEATURES_FEATURES_PATH . 'vendor/autoload.php';

		if ( ! is_readable( $autoloader ) ) {
			self::missing_autoloader_notice();
			return false;
		}

		require_once $autoloader; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable

		return true;
	}

	/**
	 * Displays a notice if the autoloader is missing.
	 */
	private static function missing_autoloader_notice(): void {
		$error_message = sprintf(
			/* translators: %s: The plugin name. */
			__( '%s: The Composer autoloader was not found. If you installed the plugin from the GitHub source code, make sure to run `composer install`.', 'amc-automata-features-features' ),
			esc_html( 'AMC Automata Features Features' )
		);

		_doing_it_wrong( self::class, esc_html( $error_message ), '1.0.0' );

		add_action(
			'admin_notices',
			static function () use ( $error_message ): void {
				wp_admin_notice(
					esc_html( $error_message ),
					[
						'type'    => 'error',
						'dismiss' => false,
					]
				);
			}
		);
	}
}
