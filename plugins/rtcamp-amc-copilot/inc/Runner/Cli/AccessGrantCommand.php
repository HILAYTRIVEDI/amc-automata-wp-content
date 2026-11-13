<?php
/**
 * `wp amc access grant <user>`.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Runner\Cli;

use AMC\Core\Capabilities;
use rtCamp\WPPrimitives\Contracts\Interfaces\CLICommand;

/**
 * Class AccessGrantCommand
 *
 * Usage:
 *
 *   wp amc access grant jane@example.com
 *   wp amc access grant 42
 *
 * CLI access is trusted (shell on the server), so no capability check; the
 * change is logged to amc_changes with source 'cli'.
 *
 * @since 0.1.0
 */
final class AccessGrantCommand implements CLICommand {

	/**
	 * {@inheritDoc}
	 */
	public static function get_name(): string {
		return 'access grant';
	}

	/**
	 * {@inheritDoc}
	 */
	public static function get_description(): string {
		return __( 'Give a user engineer access (amc_manage).', 'rtcamp-amc-copilot' );
	}

	/**
	 * WP-CLI synopsis.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function get_synopsis(): array {
		return [
			[
				'type'        => 'positional',
				'name'        => 'user',
				'description' => 'User ID, login or email.',
			],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<int, string>    $args       [ user ].
	 * @param array<string, string> $assoc_args Unused.
	 */
	public static function run( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Contract signature.
		$user   = self::resolve_user( (string) ( $args[0] ?? '' ) );
		$result = Capabilities::grant( $user->ID, get_current_user_id(), 'cli' );

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( sprintf( 'Granted amc_manage to %s.', $user->user_login ) );
	}

	/**
	 * Find a user by ID, login or email, or exit with an error.
	 *
	 * @param string $value ID, login or email.
	 */
	public static function resolve_user( string $value ): \WP_User {
		$user = false;

		if ( ctype_digit( $value ) ) {
			$user = get_user_by( 'id', (int) $value );
		} elseif ( is_email( $value ) ) {
			$user = get_user_by( 'email', $value );
		} else {
			$user = get_user_by( 'login', $value );
		}

		if ( ! $user instanceof \WP_User ) {
			\WP_CLI::error( sprintf( 'User "%s" not found.', $value ) );
		}

		return $user;
	}
}
