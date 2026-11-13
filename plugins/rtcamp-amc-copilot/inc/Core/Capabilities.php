<?php
/**
 * Capabilities: `amc_manage` (per user) and `amc_view_reports` (administrator role).
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Core;

use AMC\Storage\ChangesRepository;
use WP_User;

/**
 * Class Capabilities
 *
 * - `amc_manage`: a USER capability (WP_User::add_cap), never a role, so an
 *   engineer keeps their normal role. Console, runs, settings, reports.
 * - `amc_view_reports`: added to the `administrator` ROLE on activation.
 *   Read published reports only.
 *
 * Granting/revoking `amc_manage` needs `amc_manage` + `manage_options` from
 * the acting user (checked by the REST route / CLI command, not here) and
 * always writes a row to `amc_changes` (type `user`, action `access_grant`
 * or `access_revoke`).
 *
 * @since 0.1.0
 */
final class Capabilities {

	/**
	 * Engineer capability (console + everything else).
	 */
	public const MANAGE = 'amc_manage';

	/**
	 * Client capability (read published reports).
	 */
	public const VIEW_REPORTS = 'amc_view_reports';

	/**
	 * Activation: give administrators `amc_view_reports`.
	 *
	 * If nobody holds `amc_manage` yet, the activating user gets it so the
	 * console is reachable straight away.
	 */
	public static function on_activation(): void {
		$role = get_role( 'administrator' );

		if ( $role instanceof \WP_Role ) {
			$role->add_cap( self::VIEW_REPORTS );
		}

		$current = get_current_user_id();
		if ( $current > 0 && [] === self::engineers() ) {
			self::grant( $current, $current, 'admin' );
		}
	}

	/**
	 * Uninstall: remove both capabilities everywhere.
	 */
	public static function remove_all(): void {
		$role = get_role( 'administrator' );

		if ( $role instanceof \WP_Role ) {
			$role->remove_cap( self::VIEW_REPORTS );
		}

		foreach ( self::engineers() as $user ) {
			$user->remove_cap( self::MANAGE );
		}
	}

	/**
	 * Whether a user can grant or revoke engineer access.
	 *
	 * @param int $user_id Acting user ID.
	 */
	public static function can_manage_access( int $user_id ): bool {
		return user_can( $user_id, self::MANAGE ) && user_can( $user_id, 'manage_options' );
	}

	/**
	 * Give a user `amc_manage`.
	 *
	 * @param int    $user_id  User receiving access.
	 * @param int    $actor_id User doing it (0 = system / CLI without --user).
	 * @param string $source   amc_changes source: 'admin' or 'cli'.
	 */
	public static function grant( int $user_id, int $actor_id = 0, string $source = 'admin' ): bool|\WP_Error {
		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return new \WP_Error( 'amc_invalid_user', __( 'User not found.', 'rtcamp-amc-copilot' ), [ 'status' => 404 ] );
		}

		if ( ! $user->has_cap( self::MANAGE ) ) {
			$user->add_cap( self::MANAGE );
			self::log_change( $user, 'access_grant', $actor_id, $source );
		}

		return true;
	}

	/**
	 * Remove `amc_manage` from a user.
	 *
	 * @param int    $user_id  User losing access.
	 * @param int    $actor_id User doing it (0 = system).
	 * @param string $source   amc_changes source: 'admin' or 'cli'.
	 */
	public static function revoke( int $user_id, int $actor_id = 0, string $source = 'admin' ): bool|\WP_Error {
		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return new \WP_Error( 'amc_invalid_user', __( 'User not found.', 'rtcamp-amc-copilot' ), [ 'status' => 404 ] );
		}

		if ( isset( $user->caps[ self::MANAGE ] ) ) {
			$user->remove_cap( self::MANAGE );
			self::log_change( $user, 'access_revoke', $actor_id, $source );
		}

		return true;
	}

	/**
	 * Users holding `amc_manage` as a user capability.
	 *
	 * @return list<WP_User>
	 */
	public static function engineers(): array {
		global $wpdb;

		// User caps live in {prefix}capabilities; a LIKE on the serialized
		// value is the only core-API way to find per-user caps. Admin-only, small.
		$users = get_users(
			[
				'meta_key'     => $wpdb->get_blog_prefix() . 'capabilities', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'   => '"' . self::MANAGE . '"', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_compare' => 'LIKE',
				'number'       => 200,
			]
		);

		return array_values(
			array_filter(
				$users,
				static fn ( $user ): bool => $user instanceof WP_User && ! empty( $user->caps[ self::MANAGE ] )
			)
		);
	}

	/**
	 * Write the access change to amc_changes.
	 *
	 * @param WP_User $user     Affected user.
	 * @param string  $action   'access_grant' | 'access_revoke'.
	 * @param int     $actor_id Acting user.
	 * @param string  $source   Source label.
	 */
	private static function log_change( WP_User $user, string $action, int $actor_id, string $source ): void {
		( new ChangesRepository() )->insert(
			[
				'type'        => 'user',
				'action'      => $action,
				'item'        => $user->user_login,
				'old_version' => 'access_grant' === $action ? '' : self::MANAGE,
				'new_version' => 'access_grant' === $action ? self::MANAGE : '',
				'source'      => $source,
				'user_id'     => $actor_id,
			]
		);
	}
}
