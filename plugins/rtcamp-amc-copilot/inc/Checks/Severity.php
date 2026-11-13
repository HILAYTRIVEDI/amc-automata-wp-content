<?php
/**
 * Finding severities.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks;

/**
 * Enum Severity
 *
 * Shared by findings and vulnerability matches. Stored in amc_findings.severity.
 * Notify (A2) sends immediate alerts for the rules in the plan; severity is
 * one input (e.g. vuln.match high/critical = immediate).
 *
 * @since 0.1.0
 */
enum Severity: string {

	case INFO     = 'info';
	case LOW      = 'low';
	case MEDIUM   = 'medium';
	case HIGH     = 'high';
	case CRITICAL = 'critical';

	/**
	 * Numeric rank for sorting/comparison (info = 0 ... critical = 4).
	 */
	public function rank(): int {
		return match ( $this ) {
			self::INFO     => 0,
			self::LOW      => 1,
			self::MEDIUM   => 2,
			self::HIGH     => 3,
			self::CRITICAL => 4,
		};
	}

	/**
	 * Whether this severity is at least $other.
	 *
	 * @param Severity $other Threshold.
	 */
	public function at_least( self $other ): bool {
		return $this->rank() >= $other->rank();
	}

	/**
	 * Translated label.
	 */
	public function label(): string {
		return match ( $this ) {
			self::INFO     => __( 'Info', 'rtcamp-amc-copilot' ),
			self::LOW      => __( 'Low', 'rtcamp-amc-copilot' ),
			self::MEDIUM   => __( 'Medium', 'rtcamp-amc-copilot' ),
			self::HIGH     => __( 'High', 'rtcamp-amc-copilot' ),
			self::CRITICAL => __( 'Critical', 'rtcamp-amc-copilot' ),
		};
	}
}
