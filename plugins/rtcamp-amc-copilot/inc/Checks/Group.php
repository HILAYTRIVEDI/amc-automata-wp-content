<?php
/**
 * Check groups.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks;

/**
 * Enum Group
 *
 * The 11 groups from the plan. The backed value is stored nowhere in the DB
 * (runs store the check id) but is used in REST responses and settings UIs.
 *
 * @since 0.1.0
 */
enum Group: string {

	case UPDATES         = 'updates';
	case VULNERABILITIES = 'vulnerabilities';
	case ERRORS          = 'errors';
	case SMOKE           = 'smoke';
	case SECURITY        = 'security';
	case PERFORMANCE     = 'performance';
	case HEALTH          = 'health';
	case INVENTORY       = 'inventory';
	case USERS           = 'users';
	case HOSTING         = 'hosting';
	case DETECTION       = 'detection';

	/**
	 * Translated label.
	 */
	public function label(): string {
		return match ( $this ) {
			self::UPDATES         => __( 'Updates', 'rtcamp-amc-copilot' ),
			self::VULNERABILITIES => __( 'Vulnerabilities', 'rtcamp-amc-copilot' ),
			self::ERRORS          => __( 'Errors', 'rtcamp-amc-copilot' ),
			self::SMOKE           => __( 'Smoke', 'rtcamp-amc-copilot' ),
			self::SECURITY        => __( 'Security', 'rtcamp-amc-copilot' ),
			self::PERFORMANCE     => __( 'Performance', 'rtcamp-amc-copilot' ),
			self::HEALTH          => __( 'Health', 'rtcamp-amc-copilot' ),
			self::INVENTORY       => __( 'Inventory', 'rtcamp-amc-copilot' ),
			self::USERS           => __( 'Users', 'rtcamp-amc-copilot' ),
			self::HOSTING         => __( 'Hosting', 'rtcamp-amc-copilot' ),
			self::DETECTION       => __( 'Detection', 'rtcamp-amc-copilot' ),
		};
	}
}
