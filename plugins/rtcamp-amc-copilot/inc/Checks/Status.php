<?php
/**
 * Result statuses.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks;

/**
 * Enum Status
 *
 * What a check concluded. Stored in amc_runs.status.
 *
 * - PASS: all good.
 * - WARN: needs attention soon (e.g. a major WordPress update).
 * - FAIL: needs action now (e.g. a security release not applied).
 * - INFO: nothing to judge (inventory, detection) or data not available.
 *
 * The runner adds two run-only statuses that a check never returns:
 * 'error' (the check threw) and 'queued'/'running' (see RunsRepository).
 *
 * UI rule: always show the label next to the colour, never colour alone.
 *
 * @since 0.1.0
 */
enum Status: string {

	case PASS = 'pass';
	case WARN = 'warn';
	case FAIL = 'fail';
	case INFO = 'info';

	/**
	 * Translated label.
	 */
	public function label(): string {
		return match ( $this ) {
			self::PASS => __( 'Pass', 'rtcamp-amc-copilot' ),
			self::WARN => __( 'Warning', 'rtcamp-amc-copilot' ),
			self::FAIL => __( 'Failed', 'rtcamp-amc-copilot' ),
			self::INFO => __( 'Info', 'rtcamp-amc-copilot' ),
		};
	}
}
