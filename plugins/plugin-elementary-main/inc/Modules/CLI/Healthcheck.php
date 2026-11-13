<?php
/**
 * Healthcheck CLI command for AMC Automata Features Features.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Modules\CLI;

use rtCamp\WPPrimitives\Contracts\Interfaces\CLICommand;

/**
 * Class - Healthcheck
 *
 * Implements the `amc-automata-features-features health-check` WP-CLI command.
 */
final class Healthcheck implements CLICommand {

	/**
	 * {@inheritDoc}
	 */
	public static function get_name(): string {
		return 'health-check';
	}

	/**
	 * {@inheritDoc}
	 */
	public static function get_description(): string {
		return __( 'Run a health check on the plugin.', 'amc-automata-features-features' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $args Positional arguments passed to the command.
	 * @param array $assoc_args Associative arguments passed to the command.
	 *
	 * @return void
	 */
	public static function run( array $args = [], array $assoc_args = [] ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$checks = [
			'plugin_active'     => class_exists( 'AMC_Automata_Features\Features\Main' ),
			'constants_defined' => defined( 'AMC_AUTOMATA_FEATURES_FEATURES_PATH' ) && defined( 'AMC_AUTOMATA_FEATURES_FEATURES_URL' ),
			'composer_autoload' => class_exists( 'Composer\Autoload\ClassLoader' ),
		];

		\WP_CLI::log( 'AMC Automata Features Features Health Check' );
		\WP_CLI::log( '====================================' );

		$all_passed = true;
		foreach ( $checks as $check_name => $passed ) {
			$status = $passed ? '✓ PASS' : '✗ FAIL';
			\WP_CLI::log( "  {$check_name}: {$status}" );

			if ( ! $passed ) {
				$all_passed = false;
			}
		}

		if ( ! $all_passed ) {
			\WP_CLI::error( 'One or more health checks failed. Please investigate the issues above.' );
		}

		\WP_CLI::success( 'All health checks passed!' );
	}
}
