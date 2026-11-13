/**
 * Checks catalogue (plan: "Checks catalogue"). `schedule` is the default
 * interval; `label` is the translated name `Check::label()` returns.
 */
export const GROUP_LABELS = {
	updates: 'Updates',
	vulnerabilities: 'Vulnerabilities',
	errors: 'Errors',
	smoke: 'Smoke',
	security: 'Security',
	performance: 'Performance',
	health: 'Health',
	inventory: 'Inventory',
	users: 'Users',
	hosting: 'Hosting',
	detection: 'Detection',
};

export const CHECKS = [
	[ 'updates.core', 'updates', 'Core updates', 'twicedaily' ],
	[ 'updates.plugins', 'updates', 'Plugin updates', 'twicedaily' ],
	[ 'updates.themes', 'updates', 'Theme updates', 'twicedaily' ],
	[ 'updates.php', 'updates', 'PHP version', 'daily' ],
	[ 'updates.readiness', 'updates', 'Major-upgrade readiness', 'weekly' ],
	[ 'vuln.match', 'vulnerabilities', 'Vulnerability matches', 'twicedaily' ],
	[ 'inventory.plugins', 'inventory', 'Plugin inventory', 'weekly' ],
	[ 'inventory.themes', 'inventory', 'Theme inventory', 'weekly' ],
	[
		'inventory.mu_dropins',
		'inventory',
		'Must-use plugins and drop-ins',
		'weekly',
	],
	[ 'inventory.editor', 'inventory', 'Editor type', 'weekly' ],
	[ 'errors.fatal', 'errors', 'Fatal errors', 'hourly' ],
	[ 'errors.warnings', 'errors', 'Warnings and notices', 'hourly' ],
	[ 'errors.deprecations', 'errors', 'Deprecations', 'hourly' ],
	[ 'smoke.urls', 'smoke', 'Smoke checks', 'hourly' ],
	[ 'security.checksums', 'security', 'Core checksums', 'daily' ],
	[ 'security.config', 'security', 'Security configuration', 'daily' ],
	[ 'security.admins', 'security', 'Administrator accounts', 'daily' ],
	[ 'ssl.certificate', 'security', 'SSL certificate', 'daily' ],
	[ 'perf.response', 'performance', 'Response time', 'hourly' ],
	[ 'perf.database', 'performance', 'Database size', 'daily' ],
	[ 'perf.cache', 'performance', 'Caching', 'daily' ],
	[ 'perf.slow_queries', 'performance', 'Slow queries', 'hourly' ],
	[ 'health.site_health', 'health', 'Site Health', 'daily' ],
	[ 'health.cron', 'health', 'WP-Cron', 'hourly' ],
	[ 'users.roles', 'users', 'Roles and capabilities', 'daily' ],
	[ 'users.changes', 'users', 'User changes', 'event' ],
	[ 'hosting.platform', 'hosting', 'Hosting platform', 'weekly' ],
	[ 'detect.cdn', 'detection', 'CDN', 'weekly' ],
	[ 'detect.forms', 'detection', 'Forms', 'weekly' ],
	[ 'detect.mailer', 'detection', 'Mailer', 'weekly' ],
	[ 'detect.analytics', 'detection', 'Analytics and tagging', 'weekly' ],
	[ 'detect.accessibility', 'detection', 'Accessibility', 'weekly' ],
].map( ( [ id, group, label, schedule ] ) => ( {
	id,
	group,
	group_label: GROUP_LABELS[ group ],
	label,
	schedule,
	default_schedule: schedule,
} ) );

/**
 * Check definition by ID.
 *
 * @param {string} checkId Check ID.
 * @return {Object|undefined} Catalogue entry.
 */
export const findCheck = ( checkId ) =>
	CHECKS.find( ( check ) => check.id === checkId );

/**
 * ID of the latest mock run of a check (stable, so findings can link to it).
 *
 * @param {string} checkId Check ID.
 * @return {number} Run ID.
 */
export const latestRunId = ( checkId ) =>
	2000 + CHECKS.findIndex( ( check ) => check.id === checkId );
