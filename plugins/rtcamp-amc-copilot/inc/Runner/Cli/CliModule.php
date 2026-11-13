<?php
/**
 * Registers the `wp amc` WP-CLI commands.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Runner\Cli;

use rtCamp\WPPrimitives\Contracts\Interfaces\ConditionallyRegistrable;

/**
 * Class CliModule
 *
 * FROZEN list (Wave 0). Each command class is owned by one lane:
 *   amc check run|list       Wave 0  (CheckRunCommand, CheckListCommand)
 *   amc access grant|revoke  Wave 0  (AccessGrantCommand, AccessRevokeCommand)
 *   amc report generate      D1      (AMC\Reports\Cli\ReportGenerateCommand)
 *
 * A command class implements rtCamp CLICommand and may add a static
 * get_synopsis(): array (WP-CLI synopsis) for argument parsing/help.
 *
 * @since 0.1.0
 */
final class CliModule implements ConditionallyRegistrable {

	/**
	 * {@inheritDoc}
	 */
	public function can_register(): bool {
		return defined( 'WP_CLI' ) && WP_CLI;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'init', [ $this, 'register_commands' ] );
	}

	/**
	 * Command classes keyed by command path (after `wp amc`).
	 *
	 * @return array<string, class-string<\rtCamp\WPPrimitives\Contracts\Interfaces\CLICommand>>
	 */
	public static function commands(): array {
		return [
			CheckRunCommand::get_name()     => CheckRunCommand::class,
			CheckListCommand::get_name()    => CheckListCommand::class,
			AccessGrantCommand::get_name()  => AccessGrantCommand::class,
			AccessRevokeCommand::get_name() => AccessRevokeCommand::class,
			\AMC\Reports\Cli\ReportGenerateCommand::get_name() => \AMC\Reports\Cli\ReportGenerateCommand::class,
		];
	}

	/**
	 * Add every command to WP-CLI.
	 */
	public function register_commands(): void {
		foreach ( self::commands() as $name => $class ) {
			$args = [ 'shortdesc' => $class::get_description() ];

			if ( method_exists( $class, 'get_synopsis' ) ) {
				$args['synopsis'] = $class::get_synopsis();
			}

			\WP_CLI::add_command( "amc {$name}", [ $class, 'run' ], $args );
		}
	}
}
