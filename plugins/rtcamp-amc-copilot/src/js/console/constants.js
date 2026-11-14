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
 * Console screens, in navigation order, with the one-line description shown
 * under each screen's heading.
 *
 * @return {Array<{slug: string, label: string, description: string}>} Screens.
 */
export const getScreens = () => [
	{
		slug: 'overview',
		label: __( 'Overview', 'rtcamp-amc-copilot' ),
		description: __(
			'Status of every check group, open findings and what runs next.',
			'rtcamp-amc-copilot'
		),
	},
	{
		slug: 'updates',
		label: __( 'Updates', 'rtcamp-amc-copilot' ),
		description: __(
			'Core, plugin, theme and PHP updates, and major-upgrade readiness.',
			'rtcamp-amc-copilot'
		),
	},
	{
		slug: 'vulnerabilities',
		label: __( 'Vulnerabilities', 'rtcamp-amc-copilot' ),
		description: __(
			'Known vulnerabilities matched against the installed versions.',
			'rtcamp-amc-copilot'
		),
	},
	{
		slug: 'security',
		label: __( 'Security', 'rtcamp-amc-copilot' ),
		description: __(
			'Core file integrity, configuration, administrators and SSL.',
			'rtcamp-amc-copilot'
		),
	},
	{
		slug: 'errors',
		label: __( 'Errors', 'rtcamp-amc-copilot' ),
		description: __(
			'PHP fatal errors, warnings and deprecations, grouped by signature.',
			'rtcamp-amc-copilot'
		),
	},
	{
		slug: 'smoke',
		label: __( 'Smoke checks', 'rtcamp-amc-copilot' ),
		description: __(
			'Key URLs fetched on a schedule, with pass rate and response time.',
			'rtcamp-amc-copilot'
		),
	},
	{
		slug: 'performance',
		label: __( 'Performance', 'rtcamp-amc-copilot' ),
		description: __(
			'Response time, database and autoload size, caching and slow queries.',
			'rtcamp-amc-copilot'
		),
	},
	{
		slug: 'inventory',
		label: __( 'Inventory', 'rtcamp-amc-copilot' ),
		description: __(
			'Plugins, themes, drop-ins, editor, hosting and detected services.',
			'rtcamp-amc-copilot'
		),
	},
	{
		slug: 'runs',
		label: __( 'Runs', 'rtcamp-amc-copilot' ),
		description: __(
			'Every check run with its trigger, status, duration and raw data.',
			'rtcamp-amc-copilot'
		),
	},
	{
		slug: 'changes',
		label: __( 'Changes', 'rtcamp-amc-copilot' ),
		description: __(
			'Code and user changes on the site, and where they came from.',
			'rtcamp-amc-copilot'
		),
	},
	{
		slug: 'reports',
		label: __( 'Reports', 'rtcamp-amc-copilot' ),
		description: __(
			'Website Maintenance Reports: generate, edit and publish.',
			'rtcamp-amc-copilot'
		),
	},
	{
		slug: 'settings',
		label: __( 'Settings', 'rtcamp-amc-copilot' ),
		description: __(
			'Site details, notifications, check schedule and access.',
			'rtcamp-amc-copilot'
		),
	},
	{
		slug: 'setup',
		label: __( 'Setup', 'rtcamp-amc-copilot' ),
		description: __(
			'First-run setup: site details, report fields, notifications and the report schedule.',
			'rtcamp-amc-copilot'
		),
	},
];

/**
 * Screens shown on their own, without the section tabs (the setup wizard).
 */
export const STANDALONE_SCREENS = [ 'setup' ];

/**
 * Top-level navigation: a few sections, each holding one or more screens.
 * Sections with several screens show them as sub-navigation, so the tab row
 * never wraps. Selecting a section opens its first screen.
 *
 * @return {Array<{slug: string, label: string, screens: string[]}>} Sections.
 */
export const getSections = () => [
	{
		slug: 'overview',
		label: __( 'Overview', 'rtcamp-amc-copilot' ),
		screens: [ 'overview' ],
	},
	{
		slug: 'checks',
		label: __( 'Checks', 'rtcamp-amc-copilot' ),
		screens: [
			'updates',
			'vulnerabilities',
			'security',
			'errors',
			'smoke',
			'performance',
		],
	},
	{
		slug: 'inventory',
		label: __( 'Inventory', 'rtcamp-amc-copilot' ),
		screens: [ 'inventory' ],
	},
	{
		slug: 'activity',
		label: __( 'Activity', 'rtcamp-amc-copilot' ),
		screens: [ 'runs', 'changes' ],
	},
	{
		slug: 'reports',
		label: __( 'Reports', 'rtcamp-amc-copilot' ),
		screens: [ 'reports' ],
	},
	{
		slug: 'settings',
		label: __( 'Settings', 'rtcamp-amc-copilot' ),
		screens: [ 'settings' ],
	},
];

/**
 * The section a screen belongs to.
 *
 * @param {string} screen Screen slug.
 * @return {{slug: string, label: string, screens: string[]}} Section.
 */
export const sectionFor = ( screen ) =>
	getSections().find( ( section ) => section.screens.includes( screen ) ) ??
	getSections()[ 0 ];

/**
 * Label and description of a screen.
 *
 * @param {string} slug Screen slug.
 * @return {{slug: string, label: string, description: string}|undefined} Screen.
 */
export const getScreen = ( slug ) =>
	getScreens().find( ( screen ) => screen.slug === slug );

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
