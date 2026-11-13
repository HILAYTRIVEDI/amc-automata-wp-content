/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Admin menu slug registered by `AMC\Admin\ConsolePage` (capability `amc_manage`).
 */
export const MENU_SLUG = 'amc-copilot';

/**
 * Query arg that selects the screen inside the console.
 */
export const SCREEN_ARG = 'screen';

/**
 * Console screens, in navigation order.
 *
 * @return {Array<{slug: string, label: string}>} Screens.
 */
export const getScreens = () => [
	{ slug: 'overview', label: __( 'Overview', 'rtcamp-amc-copilot' ) },
	{ slug: 'updates', label: __( 'Updates', 'rtcamp-amc-copilot' ) },
	{
		slug: 'vulnerabilities',
		label: __( 'Vulnerabilities', 'rtcamp-amc-copilot' ),
	},
	{ slug: 'errors', label: __( 'Errors', 'rtcamp-amc-copilot' ) },
	{ slug: 'smoke', label: __( 'Smoke checks', 'rtcamp-amc-copilot' ) },
	{ slug: 'security', label: __( 'Security', 'rtcamp-amc-copilot' ) },
	{ slug: 'performance', label: __( 'Performance', 'rtcamp-amc-copilot' ) },
	{ slug: 'inventory', label: __( 'Inventory', 'rtcamp-amc-copilot' ) },
	{ slug: 'changes', label: __( 'Changes', 'rtcamp-amc-copilot' ) },
	{ slug: 'runs', label: __( 'Runs', 'rtcamp-amc-copilot' ) },
	{ slug: 'reports', label: __( 'Reports', 'rtcamp-amc-copilot' ) },
	{ slug: 'settings', label: __( 'Settings', 'rtcamp-amc-copilot' ) },
];

/**
 * Checks each screen's "Run check" button queues (only those registered on
 * the site are requested; see `RunChecksButton`).
 */
export const SCREEN_CHECKS = {
	updates: [
		'updates.core',
		'updates.plugins',
		'updates.themes',
		'updates.php',
		'updates.readiness',
	],
	vulnerabilities: [ 'vuln.match' ],
	smoke: [ 'smoke.urls' ],
	security: [
		'security.checksums',
		'security.config',
		'security.admins',
		'ssl.certificate',
	],
	performance: [
		'perf.response',
		'perf.database',
		'perf.cache',
		'perf.slow_queries',
	],
	inventory: [
		'inventory.plugins',
		'inventory.themes',
		'inventory.mu_dropins',
		'inventory.editor',
		'hosting.platform',
		'detect.cdn',
		'detect.forms',
		'detect.mailer',
		'detect.analytics',
		'detect.accessibility',
	],
};

/**
 * Check intervals offered in Settings (`''` = the check's default).
 *
 * @return {Array<{value: string, label: string}>} Interval options.
 */
export const getIntervals = () => [
	{ value: 'hourly', label: __( 'Hourly', 'rtcamp-amc-copilot' ) },
	{ value: 'twicedaily', label: __( 'Twice daily', 'rtcamp-amc-copilot' ) },
	{ value: 'daily', label: __( 'Daily', 'rtcamp-amc-copilot' ) },
	{ value: 'weekly', label: __( 'Weekly', 'rtcamp-amc-copilot' ) },
];
