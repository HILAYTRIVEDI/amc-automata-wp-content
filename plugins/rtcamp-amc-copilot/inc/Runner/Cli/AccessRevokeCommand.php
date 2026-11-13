<?php
/**
 * `wp amc access revoke <user>`.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Runner\Cli;

use AMC\Core\Capabilities;
use rtCamp\WPPrimitives\Contracts\Interfaces\CLICommand;

/**
 * Class AccessRevokeCommand
 *
 * Usage:
 *
 *   wp amc access revoke jane@example.com
 *
 * @since 0.1.0
 */
final class AccessRevokeCommand implements CLICommand {

	/**
	 * {@inheritDoc}
	 */
	public static function get_name(): string {
		return 'access revoke';
	}

	/**
	 * {@inheritDoc}
	 */
	public static function get_description(): string {
		return __( 'Remove engineer access (amc_manage) from a user.', 'rtcamp-amc-copilot' );
	}

	/**
	 * WP-CLI synopsis.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function get_synopsis(): array {
		return AccessGrantCommand::get_synopsis();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<int, string>    $args       [ user ].
	 * @param array<string, string> $assoc_args Unused.
	 */
	public static function run( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Contract signature.
		$user   = AccessGrantCommand::resolve_user( (string) ( $args[0] ?? '' ) );
		$result = Capabilities::revoke( $user->ID, get_current_user_id(), 'cli' );

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( sprintf( 'Revoked amc_manage from %s.', $user->user_login ) );
	}
}
