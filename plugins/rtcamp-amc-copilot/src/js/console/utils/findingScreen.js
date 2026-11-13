/**
 * Console screen that owns a finding, by its check group (and, when the
 * group is unknown, by the check id prefix).
 */
const BY_GROUP = {
	vulnerabilities: 'vulnerabilities',
	errors: 'errors',
	smoke: 'smoke',
	security: 'security',
	performance: 'performance',
	updates: 'updates',
	inventory: 'inventory',
	detection: 'inventory',
	hosting: 'inventory',
	users: 'overview',
	health: 'overview',
};

const BY_PREFIX = {
	vuln: 'vulnerabilities',
	errors: 'errors',
	smoke: 'smoke',
	security: 'security',
	ssl: 'security',
	perf: 'performance',
	updates: 'updates',
	inventory: 'inventory',
	detect: 'inventory',
	hosting: 'inventory',
	users: 'overview',
	health: 'overview',
};

/**
 * Screen slug for a finding.
 *
 * @param {{group?: string|null, check_id?: string}} finding Finding.
 * @return {string} Screen slug; `overview` when nothing matches.
 */
export const screenForFinding = ( finding ) =>
	BY_GROUP[ finding?.group ] ??
	BY_PREFIX[ String( finding?.check_id ?? '' ).split( '.' )[ 0 ] ] ??
	'overview';
