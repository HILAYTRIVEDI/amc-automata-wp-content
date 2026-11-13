<?php
/**
 * Hourly cap on immediate alert emails.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Notify;

use AMC\Core\Settings;

/**
 * Class Throttle
 *
 * At most `immediate_alerts_per_hour` immediate emails per site in any rolling
 * hour; the rest go to the next digest. The send times live in one option
 * (autoload off, one per site), so it works without a persistent object cache.
 *
 * @since 0.1.0
 */
final class Throttle {

	/**
	 * Option holding the unix times of recent immediate emails.
	 */
	public const OPTION = 'amc_notify_sent_times';

	/**
	 * Window length in seconds.
	 */
	public const WINDOW = HOUR_IN_SECONDS;

	/**
	 * Clock.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param callable|null $clock Returns the unix time (test seam).
	 */
	public function __construct( ?callable $clock = null ) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	/**
	 * Configured cap.
	 */
	public function limit(): int {
		return (int) Settings::get_value( 'immediate_alerts_per_hour' );
	}

	/**
	 * Emails sent in the current window.
	 */
	public function used(): int {
		return count( $this->recent() );
	}

	/**
	 * Slots left in the current window.
	 */
	public function remaining(): int {
		return max( 0, $this->limit() - $this->used() );
	}

	/**
	 * Take one slot. Call it right before sending.
	 *
	 * @return bool False when the cap is reached (send nothing; the finding waits for the digest).
	 */
	public function acquire(): bool {
		$recent = $this->recent();

		if ( count( $recent ) >= $this->limit() ) {
			return false;
		}

		$recent[] = (int) ( $this->clock )();
		update_option( self::OPTION, $recent, false );

		return true;
	}

	/**
	 * Forget the counter (deactivation and uninstall).
	 */
	public function reset(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Send times inside the window, oldest first.
	 *
	 * @return list<int>
	 */
	private function recent(): array {
		$cutoff = (int) ( $this->clock )() - self::WINDOW;
		$stored = get_option( self::OPTION, [] );

		if ( ! is_array( $stored ) ) {
			return [];
		}

		return array_values(
			array_filter(
				array_map( 'intval', $stored ),
				static fn ( int $time ): bool => $time > $cutoff
			)
		);
	}
}
