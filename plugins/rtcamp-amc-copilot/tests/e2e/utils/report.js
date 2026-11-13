/**
 * Shared constants and page helpers for the report e2e specs.
 */

/**
 * The 18 section blocks, in report order (docs/phase-1-plan.md "Reports").
 */
const SECTION_BLOCKS = [
	'amc/section-overview',
	'amc/section-cms',
	'amc/section-theme',
	'amc/section-plugins-themes',
	'amc/section-database-backups',
	'amc/section-user-management',
	'amc/section-hosting',
	'amc/section-domains',
	'amc/section-ssl',
	'amc/section-caching-cdn',
	'amc/section-security',
	'amc/section-forms',
	'amc/section-analytics',
	'amc/section-accessibility',
	'amc/section-php-errors',
	'amc/section-smoke-checks',
	'amc/section-performance',
	'amc/section-engineer-notes',
];

/**
 * The engineer's read-only "Client view" of a report (drafts included).
 *
 * @param {number} id Report post ID.
 * @return {string} Admin path and query.
 */
function engineerViewPath( id ) {
	return `edit.php?post_type=amc_report&page=amc-reports&report=${ id }`;
}

/**
 * The client administrator's "Maintenance Reports" page.
 *
 * @param {number} [id] Report post ID; omit for the list.
 * @return {string} Admin path and query.
 */
function clientViewPath( id ) {
	return id
		? `admin.php?page=amc-reports&report=${ id }`
		: 'admin.php?page=amc-reports';
}

/**
 * The `name` of every block in the editor's top level, in order.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').Editor} editor Editor.
 * @return {Promise<string[]>} Block names.
 */
async function topLevelBlockNames( editor ) {
	const blocks = await editor.getBlocks();
	return blocks.map( ( block ) => block.name );
}

module.exports = {
	SECTION_BLOCKS,
	clientViewPath,
	engineerViewPath,
	topLevelBlockNames,
};
