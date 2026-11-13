<?php
/**
 * Default check schedules.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks;

/**
 * Enum Schedule
 *
 * Values match WordPress core cron schedule names, so a settings override
 * (Settings::INTERVALS) uses the same strings. EVENT means "never scheduled":
 * the check is driven by hooks (users.changes) and only runs on demand.
 *
 * @since 0.1.0
 */
enum Schedule: string {

	case HOURLY      = 'hourly';
	case TWICE_DAILY = 'twicedaily';
	case DAILY       = 'daily';
	case WEEKLY      = 'weekly';
	case EVENT       = 'event';

	/**
	 * Interval in seconds (0 for EVENT).
	 */
	public function seconds(): int {
		return match ( $this ) {
			self::HOURLY      => HOUR_IN_SECONDS,
			self::TWICE_DAILY => 12 * HOUR_IN_SECONDS,
			self::DAILY       => DAY_IN_SECONDS,
			self::WEEKLY      => WEEK_IN_SECONDS,
			self::EVENT       => 0,
		};
	}
}
