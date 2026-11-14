<?php
/**
 * First-run setup wizard: completion state and notice dismissal.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Admin;

use AMC\Core\Capabilities;

/**
 * Class SetupState
 *
 * - `amc_setup_completed` (autoload OFF): `{ completed_at: 'Y-m-d H:i:s' UTC,
 *   user_id: int }` once someone finishes the wizard. Absent until then.
 * - `amc_setup_notice_dismissed` user meta: the user closed the "set up AMC
 *   Copilot" notice. Per user, so one engineer closing it does not hide it
 *   from the others.
 * - `amc_activation_redirect` transient: the user who just activated the
 *   plugin, so the next admin page load sends them to the wizard once.
 *
 * Running the wizard needs `amc_manage` (console access) AND `manage_options`
 * (it saves Settings, which needs both; see SettingsController).
 *
 * @since 0.1.0
 */
final class SetupState {

	/**
	 * Option holding completion.
	 */
	public const OPTION = 'amc_setup_completed';

	/**
	 * User meta set when a user dismisses the setup notice.
	 */
	public const DISMISSED_META = 'amc_setup_notice_dismissed';

	/**
	 * Transient set on activation; holds the activating user's ID.
	 */
	public const REDIRECT_TRANSIENT = 'amc_activation_redirect';

	/**
	 * Console screen slug of the wizard (`?page=amc-copilot&screen=setup`).
	 */
	public const SCREEN = 'setup';

	/**
	 * Whether the wizard has been finished on this site.
	 */
	public static function is_completed(): bool {
		return null !== self::completed_at();
	}

	/**
	 * When the wizard was finished (UTC 'Y-m-d H:i:s'), or null.
	 */
	public static function completed_at(): ?string {
		$value = get_option( self::OPTION, [] );

		if ( ! is_array( $value ) || empty( $value['completed_at'] ) || ! is_string( $value['completed_at'] ) ) {
			return null;
		}

		return $value['completed_at'];
	}

	/**
	 * Mark the wizard finished.
	 *
	 * @param int $user_id User who finished it.
	 *
	 * @return string The completion time, UTC 'Y-m-d H:i:s'.
	 */
	public static function complete( int $user_id ): string {
		$now = gmdate( 'Y-m-d H:i:s' );

		update_option(
			self::OPTION,
			[
				'completed_at' => $now,
				'user_id'      => $user_id,
			],
			false
		);

		return $now;
	}

	/**
	 * Forget completion (the notice and the activation redirect come back).
	 */
	public static function reset(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Whether a user may run the wizard.
	 *
	 * @param int $user_id User ID; 0 = the current user.
	 */
	public static function can_run( int $user_id = 0 ): bool {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();

		return $user_id > 0 && user_can( $user_id, Capabilities::MANAGE ) && user_can( $user_id, 'manage_options' );
	}

	/**
	 * Whether a user closed the setup notice.
	 *
	 * @param int $user_id User ID; 0 = the current user.
	 */
	public static function is_dismissed( int $user_id = 0 ): bool {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();

		return $user_id > 0 && (bool) get_user_meta( $user_id, self::DISMISSED_META, true );
	}

	/**
	 * Remember that a user closed the setup notice.
	 *
	 * @param int $user_id User ID.
	 */
	public static function dismiss( int $user_id ): void {
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::DISMISSED_META, 1 );
		}
	}

	/**
	 * The REST shape of the state (GET /setup without `detected`).
	 *
	 * @return array{completed: bool, completed_at: string|null, notice_dismissed: bool}
	 */
	public static function for_rest(): array {
		$completed_at = self::completed_at();

		return [
			'completed'        => null !== $completed_at,
			'completed_at'     => null === $completed_at ? null : str_replace( ' ', 'T', $completed_at ) . 'Z',
			'notice_dismissed' => self::is_dismissed(),
		];
	}
}
