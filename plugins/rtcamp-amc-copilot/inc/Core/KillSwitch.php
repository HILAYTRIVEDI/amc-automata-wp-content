<?php
/**
 * Kill switch: stops all capture and every scheduled or queued run.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Core;

/**
 * Class KillSwitch
 *
 * Two inputs, and the constant ALWAYS wins:
 *
 *   1. `define( 'AMC_COPILOT_DISABLED', true );` in wp-config.php.
 *      If the constant is defined (true OR false) the setting is ignored.
 *   2. The `kill_switch` key of the `amc_settings` option (Settings screen).
 *
 * Who must call it:
 *   - Runner / Scheduler (Wave 0): before every tick and every run.
 *   - Capture layer (lane A1): ONCE per request, lazily, at flush time in
 *     `shutdown` (not on every hook), so front-end requests with nothing to
 *     write never read the option.
 *   - Notify (lane A2): before sending any email.
 *
 * @since 0.1.0
 */
final class KillSwitch {

	/**
	 * Name of the wp-config constant.
	 */
	public const CONSTANT = 'AMC_COPILOT_DISABLED';

	/**
	 * Whether AMC Copilot is switched off.
	 *
	 * @return bool True when capture, cron runs, queued runs and alerts must stop.
	 */
	public static function is_active(): bool {
		$constant = self::constant_value();

		if ( null !== $constant ) {
			return $constant;
		}

		return (bool) Settings::get_value( 'kill_switch' );
	}

	/**
	 * Whether the switch is controlled by the constant (UI shows it read-only).
	 */
	public static function is_locked_by_constant(): bool {
		return null !== self::constant_value();
	}

	/**
	 * Value of the constant, or null when it is not defined.
	 */
	private static function constant_value(): ?bool {
		if ( ! defined( self::CONSTANT ) ) {
			return null;
		}

		return (bool) constant( self::CONSTANT );
	}
}
