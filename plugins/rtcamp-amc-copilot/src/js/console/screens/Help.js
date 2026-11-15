/**
 * Help screen: how to use AMC Copilot to produce maintenance reports.
 *
 * Written for an engineer who has never used the plugin. Static content, no
 * REST calls. Every topic is searchable by its title and keywords. Facts here
 * mirror the code (docs/BLUEPRINT.md sections 9 and 10); keep them in step
 * when the report pipeline changes.
 */

/**
 * WordPress dependencies
 */
import { Button, Notice, SearchControl } from '@wordpress/components';
import {
	createInterpolateElement,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Postbox from '../components/Postbox';
import ScreenState from '../components/ScreenState';
import { navigate, screenUrl, useLocation } from '../utils/navigation';

/**
 * A link to another console screen that switches without a reload.
 *
 * @param {Object} props          Props.
 * @param {string} props.screen   Screen slug.
 * @param {Object} props.children Link text.
 */
function ScreenLink( { screen, children } ) {
	return (
		<a
			href={ screenUrl( screen ) }
			onClick={ ( event ) => {
				if (
					event.metaKey ||
					event.ctrlKey ||
					event.shiftKey ||
					event.altKey
				) {
					return;
				}
				event.preventDefault();
				navigate( screen );
			} }
		>
			{ children }
		</a>
	);
}

/**
 * A link to another help topic (same screen, `&topic=<id>`).
 *
 * @param {Object} props          Props.
 * @param {string} props.topic    Topic id.
 * @param {Object} props.children Link text.
 */
function TopicLink( { topic, children } ) {
	return (
		<a
			href={ screenUrl( 'help', { topic } ) }
			onClick={ ( event ) => {
				if (
					event.metaKey ||
					event.ctrlKey ||
					event.shiftKey ||
					event.altKey
				) {
					return;
				}
				event.preventDefault();
				navigate( 'help', { topic } );
			} }
		>
			{ children }
		</a>
	);
}

/**
 * Translated text with `<reports>`, `<settings>`, ... tags turned into screen
 * links, `<topicediting>`, `<topicsections>`, ... into help topic links, and
 * `<code>` / `<strong>` / `<em>` kept.
 *
 * @param {string} text Translated text.
 */
const rich = ( text ) =>
	createInterpolateElement( text, {
		topicediting: <TopicLink topic="editing" />,
		topicpublishing: <TopicLink topic="publishing" />,
		topicsections: <TopicLink topic="sections" />,
		topicfields: <TopicLink topic="field-types" />,
		topicdatachecks: <TopicLink topic="data-checks" />,
		topicdatatables: <TopicLink topic="data-tables" />,
		overview: <ScreenLink screen="overview" />,
		reports: <ScreenLink screen="reports" />,
		settings: <ScreenLink screen="settings" />,
		setup: <ScreenLink screen="setup" />,
		runs: <ScreenLink screen="runs" />,
		vulnerabilities: <ScreenLink screen="vulnerabilities" />,
		code: <code />,
		strong: <strong />,
		em: <em />,
	} );

/**
 * A simple table.
 *
 * @param {Object}                      props         Props.
 * @param {string[]}                    props.head    Column headings.
 * @param {Array<Array<string|Object>>} props.rows    Rows of cells.
 * @param {string}                      props.caption Accessible caption.
 */
function HelpTable( { head, rows, caption } ) {
	return (
		<table className="widefat striped amc-help-table">
			<caption className="screen-reader-text">{ caption }</caption>
			<thead>
				<tr>
					{ head.map( ( cell ) => (
						<th key={ cell } scope="col">
							{ cell }
						</th>
					) ) }
				</tr>
			</thead>
			<tbody>
				{ rows.map( ( row, index ) => (
					<tr key={ index }>
						{ row.map( ( cell, cellIndex ) =>
							0 === cellIndex ? (
								<th key={ cellIndex } scope="row">
									{ cell }
								</th>
							) : (
								<td key={ cellIndex }>{ cell }</td>
							)
						) }
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

/**
 * The seven steps from nothing to a published report.
 */
const getQuickStart = () => [
	rich(
		__(
			'<strong>Check your access.</strong> You need AMC engineer access (given per person in <settings>Settings</settings>, Engineers tab). If the setup wizard has never been finished, run <setup>Setup</setup> first.',
			'rtcamp-amc-copilot'
		)
	),
	rich(
		__(
			'<strong>Check the data is fresh.</strong> Open <overview>Overview</overview>. If it warns that checks have not run recently, click <em>Run all checks</em> and wait for them to finish.',
			'rtcamp-amc-copilot'
		)
	),
	rich(
		__(
			'<strong>Generate the draft.</strong> In <reports>Reports</reports>, click <em>Generate report</em>, choose the period (Monthly, Quarterly, Annual or Custom) and click <em>Generate draft</em>.',
			'rtcamp-amc-copilot'
		)
	),
	rich(
		__(
			'<strong>Wait for it to build.</strong> The report shows <em>Pending</em> while its data is collected in the background, then <em>Draft</em>. Engineer recipients get a "Report draft ready" email.',
			'rtcamp-amc-copilot'
		)
	),
	rich(
		__(
			'<strong>Review and complete it.</strong> Click <em>Edit</em>. Check the detected values, correct any saved facts, and type the engineer fields (hours used, backup checks, malware scan, notes). Hide sections that do not apply.',
			'rtcamp-amc-copilot'
		)
	),
	rich(
		__(
			'<strong>Publish.</strong> Click <em>Publish</em> in the report list (or in the editor). The client administrators can now see it, and they are emailed if that is turned on in Settings.',
			'rtcamp-amc-copilot'
		)
	),
	rich(
		__(
			'<strong>Share a PDF if needed.</strong> Click <em>View</em>, then <em>Download PDF</em>, and choose "Save as PDF" in the print dialog.',
			'rtcamp-amc-copilot'
		)
	),
];

/**
 * What each check reads: [ check id, sources, kind of source, default schedule ].
 * Mirrors docs/BLUEPRINT.md section 4.3; keep the two in step when a check changes.
 *
 * @return {Array<Array<string>>} Rows.
 */
const getCheckSources = () => {
	const kind = {
		wp: __( 'WordPress data', 'rtcamp-amc-copilot' ),
		remote: __( 'Remote request', 'rtcamp-amc-copilot' ),
		tables: __( 'Plugin tables', 'rtcamp-amc-copilot' ),
		files: __( 'Files on disk', 'rtcamp-amc-copilot' ),
		db: __( 'Database', 'rtcamp-amc-copilot' ),
	};
	const schedule = {
		hourly: __( 'Hourly', 'rtcamp-amc-copilot' ),
		twicedaily: __( 'Twice daily', 'rtcamp-amc-copilot' ),
		daily: __( 'Daily', 'rtcamp-amc-copilot' ),
		weekly: __( 'Weekly', 'rtcamp-amc-copilot' ),
		event: __( 'Only when run', 'rtcamp-amc-copilot' ),
	};

	return [
		[
			'updates.core',
			__(
				'The update_core transient (filled by WordPress from api.wordpress.org)',
				'rtcamp-amc-copilot'
			),
			kind.wp,
			schedule.twicedaily,
		],
		[
			'updates.plugins',
			__(
				'The update_plugins transient and installed plugins; changelog excerpts from the wordpress.org plugin API (a few per run)',
				'rtcamp-amc-copilot'
			),
			[ kind.wp, kind.remote ].join( ', ' ),
			schedule.twicedaily,
		],
		[
			'updates.themes',
			__(
				'The update_themes transient and installed themes; changelog excerpts from the wordpress.org theme API',
				'rtcamp-amc-copilot'
			),
			[ kind.wp, kind.remote ].join( ', ' ),
			schedule.twicedaily,
		],
		[
			'updates.php',
			__(
				"The running PHP version, WordPress's PHP recommendation (from wordpress.org, cached by WordPress) and each active plugin's Requires PHP",
				'rtcamp-amc-copilot'
			),
			[ kind.wp, kind.remote ].join( ', ' ),
			schedule.daily,
		],
		[
			'updates.readiness',
			__(
				'"Tested up to" for each active plugin: wordpress.org update data, then the plugin file header, then its readme.txt',
				'rtcamp-amc-copilot'
			),
			[ kind.wp, kind.files ].join( ', ' ),
			schedule.weekly,
		],
		[
			'vuln.match',
			__(
				'Installed core, plugins and themes, sent to the connected vulnerability (CVE) provider. Nothing is checked without a provider.',
				'rtcamp-amc-copilot'
			),
			kind.remote,
			schedule.twicedaily,
		],
		[
			'inventory.plugins',
			__(
				'Installed and active plugins, the wordpress.org plugin API for each plugin (closed, last updated), and the previous plugin snapshot in amc_snapshots',
				'rtcamp-amc-copilot'
			),
			[ kind.wp, kind.remote ].join( ', ' ),
			schedule.weekly,
		],
		[
			'inventory.themes',
			__(
				'Installed themes and the active and parent theme',
				'rtcamp-amc-copilot'
			),
			kind.wp,
			schedule.weekly,
		],
		[
			'inventory.mu_dropins',
			__(
				'Must-use plugins and drop-ins in wp-content',
				'rtcamp-amc-copilot'
			),
			kind.wp,
			schedule.weekly,
		],
		[
			'inventory.editor',
			__(
				'The Classic Editor plugin and which post types use the block editor',
				'rtcamp-amc-copilot'
			),
			kind.wp,
			schedule.weekly,
		],
		[
			'errors.fatal',
			__(
				'Fatal errors in amc_events since the previous run',
				'rtcamp-amc-copilot'
			),
			kind.tables,
			schedule.hourly,
		],
		[
			'errors.warnings',
			__( 'Warnings and notices in amc_events', 'rtcamp-amc-copilot' ),
			kind.tables,
			schedule.hourly,
		],
		[
			'errors.deprecations',
			__(
				'Deprecations and "doing it wrong" messages in amc_events',
				'rtcamp-amc-copilot'
			),
			kind.tables,
			schedule.hourly,
		],
		[
			'smoke.urls',
			__(
				'Requests to the home page, the login page, the latest post or product, and the smoke URLs in Settings',
				'rtcamp-amc-copilot'
			),
			kind.remote,
			schedule.hourly,
		],
		[
			'security.checksums',
			__(
				'The official checksums from wordpress.org for this version and language, compared with the core files on disk (wp-content is skipped)',
				'rtcamp-amc-copilot'
			),
			[ kind.files, kind.remote ].join( ', ' ),
			schedule.daily,
		],
		[
			'security.config',
			__(
				'wp-config.php constants (file editing, debug, salts), the admin username, XML-RPC, and a request to the uploads folder for a directory listing. Secret values are never stored.',
				'rtcamp-amc-copilot'
			),
			[ kind.wp, kind.remote ].join( ', ' ),
			schedule.daily,
		],
		[
			'security.admins',
			__(
				'Users with the administrator role, compared with the previous admins snapshot in amc_snapshots',
				'rtcamp-amc-copilot'
			),
			[ kind.wp, kind.tables ].join( ', ' ),
			schedule.daily,
		],
		[
			'ssl.certificate',
			__(
				"A TLS connection to the site's own host to read its certificate",
				'rtcamp-amc-copilot'
			),
			kind.remote,
			schedule.daily,
		],
		[
			'perf.response',
			__(
				'Nothing new: the response times the latest smoke.urls run stored',
				'rtcamp-amc-copilot'
			),
			kind.tables,
			schedule.hourly,
		],
		[
			'perf.database',
			__(
				'Table sizes from information_schema (or SHOW TABLE STATUS) and the size of autoloaded options in wp_options',
				'rtcamp-amc-copilot'
			),
			kind.db,
			schedule.daily,
		],
		[
			'perf.cache',
			__(
				"Site Health's object cache and page cache tests (a few requests to this site)",
				'rtcamp-amc-copilot'
			),
			[ kind.wp, kind.remote ].join( ', ' ),
			schedule.daily,
		],
		[
			'perf.slow_queries',
			__(
				'Slow queries in amc_queries from the last 24 hours',
				'rtcamp-amc-copilot'
			),
			kind.tables,
			schedule.hourly,
		],
		[
			'health.site_health',
			__( "WordPress's Site Health tests", 'rtcamp-amc-copilot' ),
			kind.wp,
			schedule.daily,
		],
		[
			'health.cron',
			__(
				'The WP-Cron schedule, for events more than an hour late',
				'rtcamp-amc-copilot'
			),
			kind.wp,
			schedule.hourly,
		],
		[
			'users.roles',
			__(
				"User counts per role and each role's capabilities, compared with the previous roles snapshot",
				'rtcamp-amc-copilot'
			),
			[ kind.wp, kind.tables ].join( ', ' ),
			schedule.daily,
		],
		[
			'users.changes',
			__( 'User changes in amc_changes', 'rtcamp-amc-copilot' ),
			kind.tables,
			schedule.event,
		],
		[
			'hosting.platform',
			__(
				'Host constants, server variables, the database version, the operating system, the PHP memory limit and disk space where readable',
				'rtcamp-amc-copilot'
			),
			kind.wp,
			schedule.weekly,
		],
		[
			'detect.cdn',
			__( 'Response headers of the home page', 'rtcamp-amc-copilot' ),
			kind.remote,
			schedule.weekly,
		],
		[
			'detect.forms',
			__(
				'Active form plugins and add-ons, and form counts from the plugin where it allows',
				'rtcamp-amc-copilot'
			),
			kind.wp,
			schedule.weekly,
		],
		[
			'detect.mailer',
			__(
				'Active mail (SMTP) plugins and code hooked on phpmailer_init',
				'rtcamp-amc-copilot'
			),
			kind.wp,
			schedule.weekly,
		],
		[
			'detect.analytics',
			__(
				'Google Tag Manager and Google Analytics IDs in the home page HTML',
				'rtcamp-amc-copilot'
			),
			kind.remote,
			schedule.weekly,
		],
		[
			'detect.accessibility',
			__(
				'Known accessibility tools in the home page HTML, and a page whose address contains "accessibility"',
				'rtcamp-amc-copilot'
			),
			[ kind.remote, kind.wp ].join( ', ' ),
			schedule.weekly,
		],
	];
};
/**
 * Topic groups, in the order the topic list shows them.
 *
 * @return {Array<{id: string, label: string}>} Groups.
 */
export const getGroups = () => [
	{ id: 'start', label: __( 'Getting started', 'rtcamp-amc-copilot' ) },
	{ id: 'reports', label: __( 'Making reports', 'rtcamp-amc-copilot' ) },
	{ id: 'reference', label: __( 'Reference', 'rtcamp-amc-copilot' ) },
	{ id: 'data', label: __( 'Under the hood', 'rtcamp-amc-copilot' ) },
];

/**
 * Help topics, in reading order (group by group).
 *
 * @return {Array<{id: string, group: string, title: string, keywords: string, body: Object}>} Topics.
 */
export const getTopics = () => [
	{
		id: 'start',
		group: 'start',
		title: __( 'Start here: your first report', 'rtcamp-amc-copilot' ),
		keywords: 'quick start begin new first report steps overview checklist',
		body: (
			<>
				<p>
					{ __(
						'New to AMC Copilot? These seven steps take you from nothing to a published report. Each step links to the screen you need, and the topics in the list go into more detail.',
						'rtcamp-amc-copilot'
					) }
				</p>
				<ol className="amc-help-steps">
					{ getQuickStart().map( ( step, index ) => (
						<li key={ index }>{ step }</li>
					) ) }
				</ol>
			</>
		),
	},
	{
		id: 'about',
		group: 'start',
		title: __( 'What AMC Copilot does', 'rtcamp-amc-copilot' ),
		keywords:
			'about overview introduction checks findings alerts how it works',
		body: (
			<>
				<p>
					{ __(
						'AMC Copilot runs the recurring maintenance checks for this site on a schedule (updates, vulnerabilities, errors, security, performance, smoke URLs, inventory and more), stores every result, emails alerts for problems that cannot wait, and builds the Website Maintenance Report from the stored results.',
						'rtcamp-amc-copilot'
					) }
				</p>
				<ol>
					<li>
						{ __(
							'A check runs and stores a result: a status (Pass, Warning, Failed or Info), a summary and its data.',
							'rtcamp-amc-copilot'
						) }
					</li>
					<li>
						{ __(
							'Problems become findings with a severity (Info, Low, Medium, High or Critical). A finding stays open until a later run no longer sees the problem.',
							'rtcamp-amc-copilot'
						) }
					</li>
					<li>
						{ __(
							'Urgent findings are emailed straight away; the rest go into the daily digest.',
							'rtcamp-amc-copilot'
						) }
					</li>
					<li>
						{ __(
							'A report reads the stored results when its draft is built. It never runs a check you have not seen, so the console, the emails and the report always agree.',
							'rtcamp-amc-copilot'
						) }
					</li>
				</ol>
				<p>
					{ __(
						'The plugin only reads the site. It never applies updates or changes anything for you.',
						'rtcamp-amc-copilot'
					) }
				</p>
			</>
		),
	},
	{
		id: 'access',
		group: 'start',
		title: __( 'Access and first-time setup', 'rtcamp-amc-copilot' ),
		keywords:
			'access permission engineer grant capability amc_manage setup wizard client administrator role cannot see menu',
		body: (
			<>
				<h4>{ __( 'Who can do what', 'rtcamp-amc-copilot' ) }</h4>
				<ul>
					<li>
						{ rich(
							__(
								'<strong>Engineers</strong> use this console and generate, edit and publish reports. Access is given to each person, not to a role, in <settings>Settings</settings> on the Engineers tab, or with the <code>wp amc access grant</code> command followed by their user name. Being an administrator is not enough on its own.',
								'rtcamp-amc-copilot'
							)
						) }
					</li>
					<li>
						{ __(
							'Client administrators see only published reports, in their own "Maintenance Reports" menu. They cannot open this console or see drafts.',
							'rtcamp-amc-copilot'
						) }
					</li>
				</ul>
				<h4>{ __( 'The setup wizard', 'rtcamp-amc-copilot' ) }</h4>
				<p>
					{ rich(
						__(
							'Run <setup>Setup</setup> once per site. It asks for the client and site details, hosting and backups, domains, SSL and accessibility, who gets notifications, and the report schedule (contract start date). Those answers become the saved report fields used in every report. You can change them later in <settings>Settings</settings>.',
							'rtcamp-amc-copilot'
						)
					) }
				</p>
			</>
		),
	},
	{
		id: 'first-report',
		group: 'reports',
		title: __( 'Making a report, step by step', 'rtcamp-amc-copilot' ),
		keywords:
			'create generate new report draft period monthly quarterly annual custom pending build edit publish workflow',
		body: (
			<>
				<h4>
					{ __( '1. Before you generate', 'rtcamp-amc-copilot' ) }
				</h4>
				<p>
					{ rich(
						__(
							'Detected values are copied from the latest check results at the moment the draft is built. Open <overview>Overview</overview> first: if it says checks have not run recently, click <em>Run all checks</em> and wait. Look at <runs>Runs</runs> if you want to see exactly when each check last ran.',
							'rtcamp-amc-copilot'
						)
					) }
				</p>
				<h4>{ __( '2. Generate the draft', 'rtcamp-amc-copilot' ) }</h4>
				<p>
					{ rich(
						__(
							'In <reports>Reports</reports>, click <em>Generate report</em>. Choose <em>Monthly</em>, <em>Quarterly</em> or <em>Annual</em> for the most recently finished period, or <em>Custom</em> and pick a start and end date. Click <em>Generate draft</em>.',
							'rtcamp-amc-copilot'
						)
					) }
				</p>
				<p>
					{ rich(
						__(
							'Each period can have only one report. Its report ID looks like <code>AMC–ABC–2026–10</code> (site code, then the period). If a report already exists for that period, open that one instead.',
							'rtcamp-amc-copilot'
						)
					) }
				</p>
				<h4>{ __( '3. Wait for the build', 'rtcamp-amc-copilot' ) }</h4>
				<p>
					{ rich(
						__(
							'The new report shows <em>Pending</em> while its data is gathered by WP-Cron in the background, usually within a minute on a site with visitors. It then shows <em>Draft</em>, and the engineer recipients get a "Report draft ready" email with a link to the editor.',
							'rtcamp-amc-copilot'
						)
					) }
				</p>
				<h4>{ __( '4. Complete it', 'rtcamp-amc-copilot' ) }</h4>
				<p>
					{ rich(
						__(
							'Click <em>Edit</em> to open the draft in the block editor. Work through the sections top to bottom: check the detected values, correct saved facts that changed, and type every engineer field. See <topicediting>Editing a report</topicediting>.',
							'rtcamp-amc-copilot'
						)
					) }
				</p>
				<h4>{ __( '5. Publish and share', 'rtcamp-amc-copilot' ) }</h4>
				<p>
					{ rich(
						__(
							'Publish when you are done. <topicpublishing>Publishing, client view and PDF</topicpublishing> explains what publishing does.',
							'rtcamp-amc-copilot'
						)
					) }
				</p>
			</>
		),
	},
	{
		id: 'field-types',
		group: 'reports',
		title: __(
			'Detected, saved and engineer fields',
			'rtcamp-amc-copilot'
		),
		keywords:
			'fields auto detected saved engineer carry forward override edited reset what to fill in empty',
		body: (
			<>
				<p>
					{ __(
						'Every value in a report is one of three kinds. Knowing which kind a field is tells you whether you need to do anything.',
						'rtcamp-amc-copilot'
					) }
				</p>
				<HelpTable
					caption={ __( 'Report field kinds', 'rtcamp-amc-copilot' ) }
					head={ [
						__( 'Kind', 'rtcamp-amc-copilot' ),
						__( 'Where it comes from', 'rtcamp-amc-copilot' ),
						__( 'What you do', 'rtcamp-amc-copilot' ),
						__( 'Examples', 'rtcamp-amc-copilot' ),
					] }
					rows={ [
						[
							__( 'Detected', 'rtcamp-amc-copilot' ),
							__(
								'Filled in automatically from the check results and the change log when the draft is built.',
								'rtcamp-amc-copilot'
							),
							__(
								'Review it. If it is wrong, type a correction: the field shows an "Edited" badge, and "Reset" brings back the detected value.',
								'rtcamp-amc-copilot'
							),
							__(
								'WordPress versions, plugin updates, PHP version, SSL expiry, PHP error counts, response time.',
								'rtcamp-amc-copilot'
							),
						],
						[
							__( 'Saved', 'rtcamp-amc-copilot' ),
							__(
								'Stable facts the plugin cannot detect, entered once (Setup or Settings, Report fields tab) and carried into every new report.',
								'rtcamp-amc-copilot'
							),
							__(
								'Check they are still true. If you change one in a report, publishing that report saves the new value for the next reports too.',
								'rtcamp-amc-copilot'
							),
							__(
								'Client name, maintenance schedule, hosting type, backup method and retention, domain expiry, SSL type, accessibility standard.',
								'rtcamp-amc-copilot'
							),
						],
						[
							__( 'Engineer', 'rtcamp-amc-copilot' ),
							__(
								'Typed by you in each report. Never filled in or carried forward.',
								'rtcamp-amc-copilot'
							),
							__(
								'Fill in every one that applies to this period.',
								'rtcamp-amc-copilot'
							),
							__(
								'Hours used, backups working, most recent backup, malware scan result, remediation notes, reasons for added or removed plugins, WAVE result, Tag Assistant warnings, engineer notes.',
								'rtcamp-amc-copilot'
							),
						],
					] }
				/>
			</>
		),
	},
	{
		id: 'editing',
		group: 'reports',
		title: __( 'Editing a report', 'rtcamp-amc-copilot' ),
		keywords:
			'editor block hide section custom section carry forward override edited reset sidebar notes',
		body: (
			<ul>
				<li>
					{ __(
						'Each section of the report is a block. The 18 standard sections are locked in place: you cannot delete or reorder them, but you can hide one.',
						'rtcamp-amc-copilot'
					) }
				</li>
				<li>
					{ rich(
						__(
							'<strong>Hide a section</strong> that does not apply (for example Forms on a site without forms) with "Hide this section" in the block settings. Hidden sections are left out of the published report.',
							'rtcamp-amc-copilot'
						)
					) }
				</li>
				<li>
					{ rich(
						__(
							'<strong>Correct a value</strong> under "Detected and saved values" in the block settings. A changed value shows "Edited"; "Reset" puts the original back.',
							'rtcamp-amc-copilot'
						)
					) }
				</li>
				<li>
					{ rich(
						__(
							'<strong>Engineer fields</strong> are typed straight into the section. Bold, italic and links are allowed.',
							'rtcamp-amc-copilot'
						)
					) }
				</li>
				<li>
					{ rich(
						__(
							'<strong>Add anything else</strong> with a "Custom section" block between the standard sections: give it a title and type the content.',
							'rtcamp-amc-copilot'
						)
					) }
				</li>
				<li>
					{ rich(
						__(
							'<strong>Known limitation:</strong> the custom section\'s "Carry forward" option is saved but not acted on yet. Add the custom section again in the next report.',
							'rtcamp-amc-copilot'
						)
					) }
				</li>
				<li>
					{ __(
						'Save the draft as often as you like. Nothing is visible to the client until you publish.',
						'rtcamp-amc-copilot'
					) }
				</li>
			</ul>
		),
	},
	{
		id: 'publishing',
		group: 'reports',
		title: __( 'Publishing, client view and PDF', 'rtcamp-amc-copilot' ),
		keywords:
			'publish client view email pdf print download share send maintenance reports menu',
		body: (
			<>
				<p>
					{ __(
						'Publishing a report does three things:',
						'rtcamp-amc-copilot'
					) }
				</p>
				<ol>
					<li>
						{ __(
							'Client administrators can see it under "Maintenance Reports", read only.',
							'rtcamp-amc-copilot'
						) }
					</li>
					<li>
						{ rich(
							__(
								'An email goes to the report recipients set in <settings>Settings</settings>, and to the client administrators when "Email client administrators when a report is published" is on. It is sent once per report.',
								'rtcamp-amc-copilot'
							)
						) }
					</li>
					<li>
						{ __(
							'Saved fields you changed in this report are stored for the next reports.',
							'rtcamp-amc-copilot'
						) }
					</li>
				</ol>
				<p>
					{ rich(
						__(
							'<strong>PDF:</strong> click <em>View</em> on the report, then <em>Download PDF</em>, and choose "Save as PDF" in the browser print dialog. The page is laid out for A4.',
							'rtcamp-amc-copilot'
						)
					) }
				</p>
				<p>
					{ __(
						'A published report cannot be rebuilt. Check it before publishing.',
						'rtcamp-amc-copilot'
					) }
				</p>
			</>
		),
	},
	{
		id: 'schedule',
		group: 'reports',
		title: __( 'Automatic report schedule', 'rtcamp-amc-copilot' ),
		keywords:
			'schedule automatic cron monthly quarterly annual contract start when generated 06:00',
		body: (
			<>
				<p>
					{ __(
						'Drafts are also generated automatically. They are never published automatically: an engineer always reviews and publishes them.',
						'rtcamp-amc-copilot'
					) }
				</p>
				<HelpTable
					caption={ __( 'Report schedule', 'rtcamp-amc-copilot' ) }
					head={ [
						__( 'Report', 'rtcamp-amc-copilot' ),
						__( 'Covers', 'rtcamp-amc-copilot' ),
						__( 'Generated (site time)', 'rtcamp-amc-copilot' ),
					] }
					rows={ [
						[
							__( 'Monthly', 'rtcamp-amc-copilot' ),
							__(
								'The previous calendar month',
								'rtcamp-amc-copilot'
							),
							__(
								'1st of each month, 06:00',
								'rtcamp-amc-copilot'
							),
						],
						[
							__( 'Quarterly', 'rtcamp-amc-copilot' ),
							__(
								'The previous contract quarter',
								'rtcamp-amc-copilot'
							),
							__(
								'First day of each contract quarter, 06:15',
								'rtcamp-amc-copilot'
							),
						],
						[
							__( 'Annual', 'rtcamp-amc-copilot' ),
							__(
								'The previous 12 contract months',
								'rtcamp-amc-copilot'
							),
							__(
								'Contract anniversary, 06:30',
								'rtcamp-amc-copilot'
							),
						],
						[
							__( 'Custom', 'rtcamp-amc-copilot' ),
							__( 'Any dates you choose', 'rtcamp-amc-copilot' ),
							__(
								'Only when you generate it',
								'rtcamp-amc-copilot'
							),
						],
					] }
				/>
				<p>
					{ rich(
						__(
							'Quarters and years follow the contract start date in <settings>Settings</settings>; without one they follow the calendar. Quarterly, annual and long custom reports also get period totals and a month-by-month table.',
							'rtcamp-amc-copilot'
						)
					) }
				</p>
			</>
		),
	},
	{
		id: 'sections',
		group: 'reference',
		title: __(
			"Where each section's data comes from",
			'rtcamp-amc-copilot'
		),
		keywords:
			'sections source data empty missing wrong value overview cms theme plugins backups users hosting domains ssl caching cdn security forms analytics accessibility php errors smoke performance',
		body: (
			<>
				<p>
					{ __(
						'Use this when a value looks empty or wrong. "Latest check" values describe the site when the draft was built, not on the last day of the period. "In the period" values only count what happened between the report\'s start and end dates.',
						'rtcamp-amc-copilot'
					) }
				</p>
				<p>
					{ rich(
						__(
							'To go one level deeper, see <topicdatachecks>What each check reads</topicdatachecks> and <topicdatatables>Database tables</topicdatatables>.',
							'rtcamp-amc-copilot'
						)
					) }
				</p>
				<HelpTable
					caption={ __(
						'Report section sources',
						'rtcamp-amc-copilot'
					) }
					head={ [
						__( 'Section', 'rtcamp-amc-copilot' ),
						__( 'Filled in from', 'rtcamp-amc-copilot' ),
						__( 'You add', 'rtcamp-amc-copilot' ),
					] }
					rows={ [
						[
							__( 'Overview', 'rtcamp-amc-copilot' ),
							__(
								'Site URL, the report author, the report date; client name and schedule (saved)',
								'rtcamp-amc-copilot'
							),
							__( 'Hours used', 'rtcamp-amc-copilot' ),
						],
						[
							__( 'CMS', 'rtcamp-amc-copilot' ),
							__(
								'WordPress version at the start and end of the period (change log), editor type, multisite',
								'rtcamp-amc-copilot'
							),
							__(
								'Websites covered (saved)',
								'rtcamp-amc-copilot'
							),
						],
						[
							__( 'Theme', 'rtcamp-amc-copilot' ),
							__(
								'Active and parent theme',
								'rtcamp-amc-copilot'
							),
							__( 'Theme origin (saved)', 'rtcamp-amc-copilot' ),
						],
						[
							__( 'Plugins and themes', 'rtcamp-amc-copilot' ),
							__(
								'Updates, installs and removals in the period (change log); inactive plugins now',
								'rtcamp-amc-copilot'
							),
							__(
								'Reason for each added or removed plugin',
								'rtcamp-amc-copilot'
							),
						],
						[
							__( 'Database backups', 'rtcamp-amc-copilot' ),
							__(
								'Nothing detected: the plugin cannot see backups. Method, schedule and retention are saved fields.',
								'rtcamp-amc-copilot'
							),
							__(
								'Whether backups work, the most recent backup',
								'rtcamp-amc-copilot'
							),
						],
						[
							__( 'User management', 'rtcamp-amc-copilot' ),
							__(
								'Users per role and administrators now; users added or removed in the period',
								'rtcamp-amc-copilot'
							),
							__( 'Nothing', 'rtcamp-amc-copilot' ),
						],
						[
							__( 'Hosting', 'rtcamp-amc-copilot' ),
							__(
								'Latest hosting check: host, server, PHP, MySQL, memory limit, PHP recommendation. Hosting type, panel, server backups, owners (saved).',
								'rtcamp-amc-copilot'
							),
							__(
								'Disk space, server backups running, latest server backup',
								'rtcamp-amc-copilot'
							),
						],
						[
							__( 'Domains', 'rtcamp-amc-copilot' ),
							__(
								'Primary domain from the site URL; expiry, auto-renewal, subdomains (saved)',
								'rtcamp-amc-copilot'
							),
							__( 'Nothing', 'rtcamp-amc-copilot' ),
						],
						[
							__( 'SSL', 'rtcamp-amc-copilot' ),
							__(
								'Latest SSL check: issuer, expiry date, days left. Purchased or free, auto-renewal (saved).',
								'rtcamp-amc-copilot'
							),
							__( 'Nothing', 'rtcamp-amc-copilot' ),
						],
						[
							__( 'Caching and CDN', 'rtcamp-amc-copilot' ),
							__(
								'Latest cache and CDN checks: page cache, object cache, CDN provider',
								'rtcamp-amc-copilot'
							),
							__( 'Configured correctly', 'rtcamp-amc-copilot' ),
						],
						[
							__( 'Security', 'rtcamp-amc-copilot' ),
							__(
								'Vulnerabilities open during the period, core file checksums, security configuration',
								'rtcamp-amc-copilot'
							),
							__(
								'Remediation notes, malware scan result',
								'rtcamp-amc-copilot'
							),
						],
						[
							__( 'Forms', 'rtcamp-amc-copilot' ),
							__(
								'Latest forms and mailer checks: form plugin and add-ons, how mail is sent',
								'rtcamp-amc-copilot'
							),
							__(
								'Entries saving, notifications confirmed',
								'rtcamp-amc-copilot'
							),
						],
						[
							__( 'Analytics', 'rtcamp-amc-copilot' ),
							__(
								'GTM and Google Analytics IDs found in the home page',
								'rtcamp-amc-copilot'
							),
							__(
								'Tag Assistant warnings',
								'rtcamp-amc-copilot'
							),
						],
						[
							__( 'Accessibility', 'rtcamp-amc-copilot' ),
							__(
								'Accessibility tools on the home page, the statement page; defined standard (saved)',
								'rtcamp-amc-copilot'
							),
							__( 'WAVE result', 'rtcamp-amc-copilot' ),
						],
						[
							__( 'PHP errors', 'rtcamp-amc-copilot' ),
							__(
								'Fatal errors, warnings and deprecations captured in the period, and the most frequent ones',
								'rtcamp-amc-copilot'
							),
							__( 'Nothing', 'rtcamp-amc-copilot' ),
						],
						[
							__( 'Smoke checks', 'rtcamp-amc-copilot' ),
							__(
								'Pass rate and response time of each smoke URL in the period',
								'rtcamp-amc-copilot'
							),
							__( 'Nothing', 'rtcamp-amc-copilot' ),
						],
						[
							__( 'Performance', 'rtcamp-amc-copilot' ),
							__(
								'Response time trend in the period; database and autoload size from the latest check',
								'rtcamp-amc-copilot'
							),
							__( 'Nothing', 'rtcamp-amc-copilot' ),
						],
						[
							__( 'Engineer notes', 'rtcamp-amc-copilot' ),
							__( 'Nothing', 'rtcamp-amc-copilot' ),
							__(
								'Anything else the client should know',
								'rtcamp-amc-copilot'
							),
						],
					] }
				/>
			</>
		),
	},
	{
		id: 'troubleshooting',
		group: 'reference',
		title: __( 'Troubleshooting', 'rtcamp-amc-copilot' ),
		keywords:
			'problem stuck pending empty missing wrong already exists duplicate rebuild regenerate trash vulnerabilities provider stale cron kill switch email not received client cannot see',
		body: (
			<dl className="amc-help-faq">
				<dt>
					{ __(
						'The report is stuck on "Pending".',
						'rtcamp-amc-copilot'
					) }
				</dt>
				<dd>
					{ rich(
						__(
							'The draft is built by WP-Cron, which only runs when someone visits the site (or when the server runs it on a schedule). Visit any page of the site and refresh the report list. On the command line, <code>wp cron event run --due-now</code> runs it at once, and <code>wp amc report generate</code> builds a report without waiting.',
							'rtcamp-amc-copilot'
						)
					) }
				</dd>
				<dt>
					{ __(
						'"A report already exists for this period".',
						'rtcamp-amc-copilot'
					) }
				</dt>
				<dd>
					{ __(
						'There is one report per period. Open the existing one from the list. To start that period again, move the draft to the Trash and generate it again; anything you typed in the old draft is lost.',
						'rtcamp-amc-copilot'
					) }
				</dd>
				<dt>
					{ __(
						'A detected value is empty or wrong.',
						'rtcamp-amc-copilot'
					) }
				</dt>
				<dd>
					{ rich(
						__(
							"Find the section in <topicsections>Where each section's data comes from</topicsections> and check that its check has run (<runs>Runs</runs>, or the check's own screen). Detected values are copied when the draft is built, so running a check afterwards does not change the draft. Either correct the value in the editor, or move the draft to the Trash and generate it again.",
							'rtcamp-amc-copilot'
						)
					) }
				</dd>
				<dt>
					{ __(
						'The Vulnerabilities list is always empty.',
						'rtcamp-amc-copilot'
					) }
				</dt>
				<dd>
					{ rich(
						__(
							'Vulnerabilities come from a CVE provider. Until one is connected, the <vulnerabilities>Vulnerabilities</vulnerabilities> screen says no provider is configured and the report has none. Say so in the security remediation notes rather than reporting "no vulnerabilities".',
							'rtcamp-amc-copilot'
						)
					) }
				</dd>
				<dt>
					{ __(
						'Overview says checks have not run recently.',
						'rtcamp-amc-copilot'
					) }
				</dt>
				<dd>
					{ rich(
						__(
							'Scheduled checks also rely on WP-Cron. Make sure the site gets visits or has a real cron job calling <code>wp-cron.php</code>, and that the kill switch in <settings>Settings</settings> is off. Click <em>Run all checks</em> to catch up.',
							'rtcamp-amc-copilot'
						)
					) }
				</dd>
				<dt>
					{ __(
						'Nothing is being checked or generated.',
						'rtcamp-amc-copilot'
					) }
				</dt>
				<dd>
					{ rich(
						__(
							'The kill switch (<settings>Settings</settings>, General tab) stops all capture, scheduled checks and scheduled reports. Turn it off when the site is ready.',
							'rtcamp-amc-copilot'
						)
					) }
				</dd>
				<dt>
					{ __( 'Emails are not arriving.', 'rtcamp-amc-copilot' ) }
				</dt>
				<dd>
					{ rich(
						__(
							'Check the engineer recipient emails and the report recipients in <settings>Settings</settings>. Urgent alerts are limited per hour; everything else arrives in the daily digest at the digest time.',
							'rtcamp-amc-copilot'
						)
					) }
				</dd>
				<dt>
					{ __(
						'The client cannot see the report.',
						'rtcamp-amc-copilot'
					) }
				</dt>
				<dd>
					{ __(
						'Only published reports are visible to clients, under "Maintenance Reports", and only to administrators of the site. Check the report status is Published.',
						'rtcamp-amc-copilot'
					) }
				</dd>
			</dl>
		),
	},
	{
		id: 'glossary',
		group: 'reference',
		title: __( 'Glossary', 'rtcamp-amc-copilot' ),
		keywords:
			'glossary terms meaning definition check run finding severity status digest snapshot report id engineer kill switch',
		body: (
			<dl className="amc-help-glossary">
				<dt>{ __( 'Check', 'rtcamp-amc-copilot' ) }</dt>
				<dd>
					{ __(
						'One automated inspection of the site, such as "Plugin updates" or "SSL certificate". It only reads.',
						'rtcamp-amc-copilot'
					) }
				</dd>
				<dt>{ __( 'Run', 'rtcamp-amc-copilot' ) }</dt>
				<dd>
					{ __(
						'One execution of a check, with its status, summary and data. Every run is listed under Activity.',
						'rtcamp-amc-copilot'
					) }
				</dd>
				<dt>{ __( 'Status', 'rtcamp-amc-copilot' ) }</dt>
				<dd>
					{ __(
						'The outcome of a run: Pass, Warning, Failed or Info (nothing to judge).',
						'rtcamp-amc-copilot'
					) }
				</dd>
				<dt>{ __( 'Finding', 'rtcamp-amc-copilot' ) }</dt>
				<dd>
					{ __(
						'One problem a check found, with a severity. It stays open until a later run no longer sees it. You can also accept, mute or resolve some findings by hand.',
						'rtcamp-amc-copilot'
					) }
				</dd>
				<dt>{ __( 'Severity', 'rtcamp-amc-copilot' ) }</dt>
				<dd>
					{ __(
						'How serious a finding is: Info, Low, Medium, High or Critical.',
						'rtcamp-amc-copilot'
					) }
				</dd>
				<dt>{ __( 'Digest', 'rtcamp-amc-copilot' ) }</dt>
				<dd>
					{ __(
						'The daily email that lists new and still-open findings that did not need an immediate alert.',
						'rtcamp-amc-copilot'
					) }
				</dd>
				<dt>{ __( 'Snapshot', 'rtcamp-amc-copilot' ) }</dt>
				<dd>
					{ __(
						'The copy of every detected and saved value taken when a draft is built. The report shows the snapshot, so it does not change when later checks run.',
						'rtcamp-amc-copilot'
					) }
				</dd>
				<dt>{ __( 'Report ID', 'rtcamp-amc-copilot' ) }</dt>
				<dd>
					{ __(
						'The unique name of a report, built from the site code and the period, for example AMC–ABC–2026–10 or AMC-ABC-2026-Q3.',
						'rtcamp-amc-copilot'
					) }
				</dd>
				<dt>{ __( 'Kill switch', 'rtcamp-amc-copilot' ) }</dt>
				<dd>
					{ __(
						'A setting that stops all capture, scheduled checks and scheduled reports at once.',
						'rtcamp-amc-copilot'
					) }
				</dd>
			</dl>
		),
	},
	{
		id: 'cli',
		group: 'reference',
		title: __( 'Command line (WP-CLI)', 'rtcamp-amc-copilot' ),
		keywords: 'wp-cli command line terminal ssh wp amc',
		body: (
			<>
				<p>
					{ __(
						'Everything in this help can be done in the console. These commands help on servers and in scripts.',
						'rtcamp-amc-copilot'
					) }
				</p>
				<HelpTable
					caption={ __( 'WP-CLI commands', 'rtcamp-amc-copilot' ) }
					head={ [
						__( 'Command', 'rtcamp-amc-copilot' ),
						__( 'What it does', 'rtcamp-amc-copilot' ),
					] }
					rows={ [
						[
							<code key="c">wp amc check list</code>,
							__(
								'Lists the checks with their schedule and last run.',
								'rtcamp-amc-copilot'
							),
						],
						[
							<code key="c">
								wp amc check run &lt;check-id&gt; | --all
							</code>,
							__(
								'Runs checks now and stores the results.',
								'rtcamp-amc-copilot'
							),
						],
						[
							<code key="c">
								wp amc report generate
								--period=month|quarter|year|custom
							</code>,
							__(
								'Generates and builds a draft at once. For custom, add --start=YYYY-MM-DD and --end=YYYY-MM-DD.',
								'rtcamp-amc-copilot'
							),
						],
						[
							<code key="c">
								wp amc access grant &lt;user&gt;
							</code>,
							__(
								'Gives a user engineer access.',
								'rtcamp-amc-copilot'
							),
						],
						[
							<code key="c">
								wp amc access revoke &lt;user&gt;
							</code>,
							__(
								'Removes engineer access.',
								'rtcamp-amc-copilot'
							),
						],
					] }
				/>
			</>
		),
	},
	{
		id: 'data-flow',
		group: 'data',
		title: __( 'How data moves through the plugin', 'rtcamp-amc-copilot' ),
		keywords:
			'data flow architecture pipeline where stored database cron capture run finding change snapshot raw sql query inspect debug',
		body: (
			<>
				<p>
					{ __(
						'Everything on the console and in a report comes from one of five paths. Each path writes to its own place, so when a value looks wrong, find its path first.',
						'rtcamp-amc-copilot'
					) }
				</p>
				<HelpTable
					caption={ __( 'Data paths', 'rtcamp-amc-copilot' ) }
					head={ [
						__( 'Path', 'rtcamp-amc-copilot' ),
						__( 'When it happens', 'rtcamp-amc-copilot' ),
						__( 'Where it writes', 'rtcamp-amc-copilot' ),
					] }
					rows={ [
						[
							__( 'Checks', 'rtcamp-amc-copilot' ),
							__(
								'On WP-Cron (each check on its own schedule), "Run now", Run all checks, or wp amc check run',
								'rtcamp-amc-copilot'
							),
							rich(
								__(
									'One <code>amc_runs</code> row per run, holding the result in its data column. Problems become <code>amc_findings</code> rows, which resolve when a later full run no longer finds them.',
									'rtcamp-amc-copilot'
								)
							),
						],
						[
							__( 'Capture', 'rtcamp-amc-copilot' ),
							__(
								'On every page request, kept in memory and written once at shutdown',
								'rtcamp-amc-copilot'
							),
							rich(
								__(
									'PHP errors and deprecations to <code>amc_events</code>; slow queries (100 ms or more, 1 request in 100 sampled) to <code>amc_queries</code>. Both are counted per hour, not one row per occurrence.',
									'rtcamp-amc-copilot'
								)
							),
						],
						[
							__( 'Change log', 'rtcamp-amc-copilot' ),
							__(
								'When a plugin, theme, core or user changes (WordPress hooks), and when the weekly plugin inventory finds a change no hook saw (a deploy)',
								'rtcamp-amc-copilot'
							),
							rich(
								__(
									'<code>amc_changes</code>, with the source: admin, cli, auto or deploy.',
									'rtcamp-amc-copilot'
								)
							),
						],
						[
							__(
								'Settings and saved fields',
								'rtcamp-amc-copilot'
							),
							__(
								'When an engineer saves Settings, the setup wizard or a report',
								'rtcamp-amc-copilot'
							),
							rich(
								__(
									'The <code>amc_settings</code> and <code>amc_saved_fields</code> options.',
									'rtcamp-amc-copilot'
								)
							),
						],
						[
							__( 'Reports', 'rtcamp-amc-copilot' ),
							__(
								'When a draft is generated (built in the background) and when it is edited',
								'rtcamp-amc-copilot'
							),
							rich(
								__(
									'An <code>amc_report</code> post. The collected values are frozen in its <code>_amc_snapshot</code> meta; your edits live in the section blocks in the post content.',
									'rtcamp-amc-copilot'
								)
							),
						],
					] }
				/>
				<h3>
					{ __(
						'Look at the raw data yourself',
						'rtcamp-amc-copilot'
					) }
				</h3>
				<p>
					{ rich(
						__(
							"In the console, open <runs>Runs</runs> and click a run to see exactly what the check stored. On the server, these read-only commands help. Replace <code>wp_</code> with the site's table prefix; on multisite the tables are shared by the network and filtered by <code>blog_id</code>.",
							'rtcamp-amc-copilot'
						)
					) }
				</p>
				<ul>
					<li>
						<code>
							{
								'wp db query "SELECT id, check_id, status, created_at FROM wp_amc_runs ORDER BY id DESC LIMIT 20"'
							}
						</code>
					</li>
					<li>
						<code>
							{
								'wp db query "SELECT check_id, severity, title, first_seen FROM wp_amc_findings WHERE status = \'open\'"'
							}
						</code>
					</li>
					<li>
						<code>
							{
								'wp post meta get <report-post-id> _amc_snapshot'
							}
						</code>
					</li>
					<li>
						<code>
							{ 'wp option get amc_settings --format=json' }
						</code>
					</li>
				</ul>
				<p>
					{ __(
						'Do not edit these tables by hand. Use the console, so that findings, alerts and the change log stay consistent.',
						'rtcamp-amc-copilot'
					) }
				</p>
			</>
		),
	},
	{
		id: 'data-tables',
		group: 'data',
		title: __( 'Database tables', 'rtcamp-amc-copilot' ),
		keywords:
			'database tables mysql schema amc_runs amc_findings amc_events amc_queries amc_changes amc_snapshots amc_locks retention size bloat delete cleanup',
		body: (
			<>
				<p>
					{ rich(
						__(
							'The plugin creates seven tables, named with the table prefix (for example <code>wp_amc_runs</code>). Dates are stored in UTC. Old rows are deleted by a daily clean-up; the retention period ("Keep check history for") is in <settings>Settings</settings> and is 13 months by default.',
							'rtcamp-amc-copilot'
						)
					) }
				</p>
				<HelpTable
					caption={ __( 'Plugin tables', 'rtcamp-amc-copilot' ) }
					head={ [
						__( 'Table', 'rtcamp-amc-copilot' ),
						__( 'One row is', 'rtcamp-amc-copilot' ),
						__( 'Written by', 'rtcamp-amc-copilot' ),
						__( 'Shown in', 'rtcamp-amc-copilot' ),
						__( 'Kept for', 'rtcamp-amc-copilot' ),
					] }
					rows={ [
						[
							<code key="t">amc_runs</code>,
							__(
								'One check execution, with its status, summary and full result (JSON). A "queued" row is a Run now request waiting to start.',
								'rtcamp-amc-copilot'
							),
							__( 'The check runner', 'rtcamp-amc-copilot' ),
							__(
								'Every screen (latest run per check), Runs, every report section marked "latest check"',
								'rtcamp-amc-copilot'
							),
							__( 'Retention period', 'rtcamp-amc-copilot' ),
						],
						[
							<code key="t">amc_findings</code>,
							__(
								'One problem, from when it is first seen until it is resolved. If it comes back later, that is a new row.',
								'rtcamp-amc-copilot'
							),
							__(
								'The check runner; engineers (resolve, mute, accept risk)',
								'rtcamp-amc-copilot'
							),
							__(
								'Overview counts, Vulnerabilities, Security, Errors, alerts and the digest, the report Security section',
								'rtcamp-amc-copilot'
							),
							__(
								'Open: forever. Resolved: retention period after resolving.',
								'rtcamp-amc-copilot'
							),
						],
						[
							<code key="t">amc_events</code>,
							__(
								'One PHP error, warning, notice, deprecation or "doing it wrong" message, counted per hour',
								'rtcamp-amc-copilot'
							),
							__(
								'Capture, on every request',
								'rtcamp-amc-copilot'
							),
							__(
								'Errors screen, the errors.* checks, the report PHP errors section',
								'rtcamp-amc-copilot'
							),
							__( 'Retention period', 'rtcamp-amc-copilot' ),
						],
						[
							<code key="t">amc_queries</code>,
							__(
								'One slow database query (by its normalised SQL), counted per hour with average and maximum time',
								'rtcamp-amc-copilot'
							),
							__(
								'Capture, on 1 request in 100',
								'rtcamp-amc-copilot'
							),
							__(
								'Performance screen (slow queries)',
								'rtcamp-amc-copilot'
							),
							__( '90 days', 'rtcamp-amc-copilot' ),
						],
						[
							<code key="t">amc_changes</code>,
							__(
								'One change: a plugin, theme or core install, update, activation or removal; a user added, removed or given a new role; engineer access granted or revoked',
								'rtcamp-amc-copilot'
							),
							__(
								'WordPress hooks; the plugin inventory (deploys); the access screen',
								'rtcamp-amc-copilot'
							),
							__(
								'Changes screen, the report CMS, Plugins and themes, and User management sections',
								'rtcamp-amc-copilot'
							),
							__( 'Retention period', 'rtcamp-amc-copilot' ),
						],
						[
							<code key="t">amc_snapshots</code>,
							__(
								'A saved copy of the plugin list, theme list, roles or administrators, used to spot what changed since last time',
								'rtcamp-amc-copilot'
							),
							__(
								'The inventory, roles and administrator checks',
								'rtcamp-amc-copilot'
							),
							__(
								'Nowhere directly; it is the "before" for the next comparison',
								'rtcamp-amc-copilot'
							),
							__( 'Retention period', 'rtcamp-amc-copilot' ),
						],
						[
							<code key="t">amc_locks</code>,
							__(
								'A job that is running now, so it is not started twice',
								'rtcamp-amc-copilot'
							),
							__(
								'The runner, the scheduler and the clean-up',
								'rtcamp-amc-copilot'
							),
							__( 'Nowhere', 'rtcamp-amc-copilot' ),
							__(
								'Deleted when the job finishes',
								'rtcamp-amc-copilot'
							),
						],
					] }
				/>
			</>
		),
	},
	{
		id: 'data-wordpress',
		group: 'data',
		title: __(
			'Options, report posts and other WordPress data',
			'rtcamp-amc-copilot'
		),
		keywords:
			'options wp_options post meta user meta transients capabilities settings saved fields amc_settings amc_saved_fields amc_report _amc_snapshot core data update_plugins',
		body: (
			<>
				<h3>{ __( 'Options', 'rtcamp-amc-copilot' ) }</h3>
				<p>
					{ __(
						'All plugin options are stored with autoload off, so they never load on visitor requests.',
						'rtcamp-amc-copilot'
					) }
				</p>
				<HelpTable
					caption={ __( 'Plugin options', 'rtcamp-amc-copilot' ) }
					head={ [
						__( 'Option', 'rtcamp-amc-copilot' ),
						__( 'Holds', 'rtcamp-amc-copilot' ),
					] }
					rows={ [
						[
							<code key="o">amc_settings</code>,
							__(
								'Everything on the Settings screen and the setup wizard: recipients, digest time, smoke URLs, per-check schedules, retention, kill switch, disclaimers, environment, contract start.',
								'rtcamp-amc-copilot'
							),
						],
						[
							<code key="o">amc_saved_fields</code>,
							__(
								'Saved report facts (client name, hosting type, backup method, domain expiry …) that every new report starts from. Updated when a report is published.',
								'rtcamp-amc-copilot'
							),
						],
						[
							<code key="o">amc_setup_completed</code>,
							__(
								'When the setup wizard was finished, and by whom.',
								'rtcamp-amc-copilot'
							),
						],
						[
							<code key="o">amc_notify_sent_times</code>,
							__(
								'Times of recent alert emails, for the hourly alert limit.',
								'rtcamp-amc-copilot'
							),
						],
						[
							<code key="o">
								amc_delete_reports_on_uninstall
							</code>,
							__(
								'Whether uninstalling the plugin also deletes reports.',
								'rtcamp-amc-copilot'
							),
						],
						[
							<code key="o">amc_schema_version</code>,
							__(
								'The table schema version, used to upgrade the tables.',
								'rtcamp-amc-copilot'
							),
						],
					] }
				/>
				<h3>{ __( 'Reports', 'rtcamp-amc-copilot' ) }</h3>
				<p>
					{ rich(
						__(
							'Each report is a private post of type <code>amc_report</code>. Its sections are blocks in the post content, and each block keeps your overrides, engineer fields and the hidden flag. The post meta holds:',
							'rtcamp-amc-copilot'
						)
					) }
				</p>
				<HelpTable
					caption={ __( 'Report post meta', 'rtcamp-amc-copilot' ) }
					head={ [
						__( 'Meta key', 'rtcamp-amc-copilot' ),
						__( 'Holds', 'rtcamp-amc-copilot' ),
					] }
					rows={ [
						[
							<code key="m">_amc_snapshot</code>,
							__(
								'Every collected value of the report as JSON, frozen when the draft was built. This is what the editor and the client view show, together with your edits.',
								'rtcamp-amc-copilot'
							),
						],
						[
							<code key="m">_amc_snapshot_taken_at</code>,
							__(
								'When the snapshot was built (UTC).',
								'rtcamp-amc-copilot'
							),
						],
						[
							<code key="m">_amc_build_status</code>,
							__(
								'pending, ready or failed. Pending means the background build has not finished yet.',
								'rtcamp-amc-copilot'
							),
						],
						[
							<code key="m">
								_amc_period_type, _amc_period_start,
								_amc_period_end
							</code>,
							__(
								'The report period (month, quarter, year or custom) and its dates.',
								'rtcamp-amc-copilot'
							),
						],
						[
							<code key="m">_amc_report_id</code>,
							__(
								'The report ID shown to the client, for example AMC–NWT–2026–10.',
								'rtcamp-amc-copilot'
							),
						],
						[
							<code key="m">_amc_environment</code>,
							__(
								'The environment label when the report was generated.',
								'rtcamp-amc-copilot'
							),
						],
						[
							<code key="m">_amc_published_email_sent</code>,
							__(
								'Set once the client was emailed, so they are never emailed twice.',
								'rtcamp-amc-copilot'
							),
						],
					] }
				/>
				<h3>
					{ __(
						'Users, access and short-lived data',
						'rtcamp-amc-copilot'
					) }
				</h3>
				<ul>
					<li>
						{ rich(
							__(
								"<strong>Engineer access</strong> is the <code>amc_manage</code> capability, added to each engineer's user account (never to a role). Client administrators read published reports through <code>amc_view_reports</code>, added to the administrator role.",
								'rtcamp-amc-copilot'
							)
						) }
					</li>
					<li>
						{ rich(
							__(
								'<strong>User meta</strong> <code>amc_setup_notice_dismissed</code>: the user closed the setup notice.',
								'rtcamp-amc-copilot'
							)
						) }
					</li>
					<li>
						{ rich(
							__(
								'<strong>Transients</strong>: <code>amc_dashboard_overview_{blog_id}</code> caches the Dashboard widget for 5 minutes; <code>amc_activation_redirect</code> sends the activating user to setup once.',
								'rtcamp-amc-copilot'
							)
						) }
					</li>
				</ul>
				<h3>
					{ __(
						'WordPress data the plugin only reads',
						'rtcamp-amc-copilot'
					) }
				</h3>
				<ul>
					<li>
						{ rich(
							__(
								'The update transients <code>update_core</code>, <code>update_plugins</code> and <code>update_themes</code>. WordPress fills them from api.wordpress.org; the plugin never asks for updates itself, so "Updates" is only as fresh as WordPress\'s last update check.',
								'rtcamp-amc-copilot'
							)
						) }
					</li>
					<li>
						{ rich(
							__(
								'Installed plugins and themes, must-use plugins and drop-ins, <code>active_plugins</code>, the active theme, users and roles, the WP-Cron schedule, and the Site Health tests.',
								'rtcamp-amc-copilot'
							)
						) }
					</li>
				</ul>
			</>
		),
	},
	{
		id: 'data-checks',
		group: 'data',
		title: __( 'What each check reads', 'rtcamp-amc-copilot' ),
		keywords:
			'checks sources files disk remote request http api wordpress.org home page loopback information_schema checksums readme.txt headers tls certificate provider cve cron schedule',
		body: (
			<>
				<p>
					{ rich(
						__(
							'Checks only read; they never change the site. Each one stores what it found in <code>amc_runs</code>. "Remote" means a request leaves the server (or loops back to this site), so it can fail when the network or a firewall blocks it. A check whose source did not answer stores a partial result and resolves nothing.',
							'rtcamp-amc-copilot'
						)
					) }
				</p>
				<HelpTable
					caption={ __( 'Check sources', 'rtcamp-amc-copilot' ) }
					head={ [
						__( 'Check', 'rtcamp-amc-copilot' ),
						__( 'Reads', 'rtcamp-amc-copilot' ),
						__( 'Kind', 'rtcamp-amc-copilot' ),
						__( 'Default schedule', 'rtcamp-amc-copilot' ),
					] }
					rows={ getCheckSources().map( ( [ id, ...cells ] ) => [
						<code key="c">{ id }</code>,
						...cells,
					] ) }
				/>
			</>
		),
	},
	{
		id: 'data-screens',
		group: 'data',
		title: __(
			'Where each console screen gets its data',
			'rtcamp-amc-copilot'
		),
		keywords:
			'console screens rest api endpoint route overview updates vulnerabilities security errors smoke performance inventory runs changes reports settings',
		body: (
			<>
				<p>
					{ rich(
						__(
							"Every screen loads its data from one REST route under <code>/wp-json/amc/v1/</code>, which only engineers can call. Screens show the latest stored results; they do not run checks unless you click Run now. For where report sections come from, see <topicsections>Where each section's data comes from</topicsections>.",
							'rtcamp-amc-copilot'
						)
					) }
				</p>
				<HelpTable
					caption={ __(
						'Console screen sources',
						'rtcamp-amc-copilot'
					) }
					head={ [
						__( 'Screen', 'rtcamp-amc-copilot' ),
						__( 'Route', 'rtcamp-amc-copilot' ),
						__( 'Data from', 'rtcamp-amc-copilot' ),
					] }
					rows={ [
						[
							__( 'Overview', 'rtcamp-amc-copilot' ),
							<code key="r">GET /overview</code>,
							rich(
								__(
									'Latest run of each enabled check (<code>amc_runs</code>), open findings by severity (<code>amc_findings</code>), the update transients, and the schedule (checks older than two intervals are flagged as not run recently).',
									'rtcamp-amc-copilot'
								)
							),
						],
						[
							__( 'Updates', 'rtcamp-amc-copilot' ),
							<code key="r">GET /updates</code>,
							__(
								'Latest runs of the updates checks (core, plugins, themes, PHP, upgrade readiness).',
								'rtcamp-amc-copilot'
							),
						],
						[
							__( 'Vulnerabilities', 'rtcamp-amc-copilot' ),
							<code key="r">GET /vulnerabilities</code>,
							__(
								'The vulnerability provider state, the latest vuln.match run and its findings. Empty until a CVE provider is connected.',
								'rtcamp-amc-copilot'
							),
						],
						[
							__( 'Security', 'rtcamp-amc-copilot' ),
							<code key="r">GET /security</code>,
							__(
								'Latest runs of the core checksums, configuration, administrators and SSL checks, and their open findings.',
								'rtcamp-amc-copilot'
							),
						],
						[
							__( 'Errors', 'rtcamp-amc-copilot' ),
							<code key="r">GET /errors</code>,
							rich(
								__(
									'<code>amc_events</code> grouped by message, with daily counts, joined to the errors.* findings.',
									'rtcamp-amc-copilot'
								)
							),
						],
						[
							__( 'Smoke checks', 'rtcamp-amc-copilot' ),
							<code key="r">GET /smoke</code>,
							__(
								'The latest smoke.urls run and the smoke URLs in Settings.',
								'rtcamp-amc-copilot'
							),
						],
						[
							__( 'Performance', 'rtcamp-amc-copilot' ),
							<code key="r">GET /performance</code>,
							rich(
								__(
									'Latest runs of the performance checks, the response time trend, and slow queries from <code>amc_queries</code>.',
									'rtcamp-amc-copilot'
								)
							),
						],
						[
							__( 'Inventory', 'rtcamp-amc-copilot' ),
							<code key="r">GET /inventory</code>,
							__(
								'Latest runs of the inventory, hosting and detection checks.',
								'rtcamp-amc-copilot'
							),
						],
						[
							__( 'Runs', 'rtcamp-amc-copilot' ),
							<code key="r">GET /runs</code>,
							rich(
								__(
									'<code>amc_runs</code> with the full stored result, and the findings each run raised.',
									'rtcamp-amc-copilot'
								)
							),
						],
						[
							__( 'Changes', 'rtcamp-amc-copilot' ),
							<code key="r">GET /changes</code>,
							rich(
								__(
									'<code>amc_changes</code>.',
									'rtcamp-amc-copilot'
								)
							),
						],
						[
							__( 'Reports', 'rtcamp-amc-copilot' ),
							<code key="r">GET /reports</code>,
							rich(
								__(
									'<code>amc_report</code> posts and their meta, and the <code>amc_saved_fields</code> option.',
									'rtcamp-amc-copilot'
								)
							),
						],
						[
							__( 'Settings', 'rtcamp-amc-copilot' ),
							<code key="r">GET /settings</code>,
							rich(
								__(
									'The <code>amc_settings</code> option, users with engineer access, the check list, and <code>amc_saved_fields</code>.',
									'rtcamp-amc-copilot'
								)
							),
						],
						[
							__( 'Dashboard widget', 'rtcamp-amc-copilot' ),
							__( 'None (server side)', 'rtcamp-amc-copilot' ),
							__(
								'The same data as Overview, cached for 5 minutes.',
								'rtcamp-amc-copilot'
							),
						],
					] }
				/>
			</>
		),
	},
];

/**
 * Whether a topic matches a search, by title and keywords (every word).
 *
 * @param {{title: string, keywords: string}} topic Topic.
 * @param {string}                            query Search text.
 */
const matches = ( topic, query ) => {
	const haystack = `${ topic.title } ${ topic.keywords }`.toLowerCase();

	return query
		.toLowerCase()
		.split( /\s+/ )
		.filter( Boolean )
		.every( ( word ) => haystack.includes( word ) );
};

/**
 * Help centre: a grouped topic list beside one topic at a time.
 *
 * The open topic lives in the URL (`&topic=<id>`), so a topic can be linked
 * to, and Back and Forward move between topics. An unknown topic opens
 * "Start here". While searching, the list shows only matching topics and the
 * open topic moves to the first match if it no longer matches.
 */
export default function Help() {
	const location = useLocation();
	const [ query, setQuery ] = useState( '' );
	const headingRef = useRef( null );
	const lastTopic = useRef( null );

	const topics = getTopics();
	const searching = '' !== query.trim();
	const shown = searching
		? topics.filter( ( topic ) => matches( topic, query ) )
		: topics;
	const requested = shown.find( ( topic ) => topic.id === location.topic );
	const current = requested ?? shown[ 0 ] ?? null;
	const index = current ? shown.indexOf( current ) : -1;
	const previous = index > 0 ? shown[ index - 1 ] : null;
	const next =
		index >= 0 && index < shown.length - 1 ? shown[ index + 1 ] : null;

	// Move focus to the topic heading when the reader picks another topic
	// (not on first load, and not while typing in the search box).
	useEffect( () => {
		const id = location.topic ?? null;
		if ( null !== lastTopic.current && id !== lastTopic.current ) {
			headingRef.current?.focus();
		}
		lastTopic.current = id ?? '';
	}, [ location.topic ] );

	const open = ( topic ) => navigate( 'help', { topic: topic.id } );

	return (
		<ScreenState screen="help" hasData>
			<div className="amc-help">
				<div className="amc-help-sidebar">
					<SearchControl
						className="amc-help-search"
						label={ __( 'Search help', 'rtcamp-amc-copilot' ) }
						placeholder={ __(
							'Search, for example "pending" or "PDF"',
							'rtcamp-amc-copilot'
						) }
						value={ query }
						onChange={ setQuery }
						__nextHasNoMarginBottom
					/>
					<p
						className="description amc-help-count"
						aria-live="polite"
					>
						{ searching &&
							sprintf(
								/* translators: %d: Number of help topics. */
								_n(
									'%d topic matches.',
									'%d topics match.',
									shown.length,
									'rtcamp-amc-copilot'
								),
								shown.length
							) }
					</p>
					<nav
						className="amc-help-contents"
						aria-label={ __( 'Help topics', 'rtcamp-amc-copilot' ) }
					>
						{ getGroups().map( ( group ) => {
							const items = shown.filter(
								( topic ) => topic.group === group.id
							);
							if ( 0 === items.length ) {
								return null;
							}
							return (
								<div
									key={ group.id }
									className="amc-help-group"
								>
									<h3 className="amc-help-group-label">
										{ group.label }
									</h3>
									<ul>
										{ items.map( ( topic ) => (
											<li key={ topic.id }>
												<a
													href={ screenUrl( 'help', {
														topic: topic.id,
													} ) }
													aria-current={
														topic === current
															? 'page'
															: undefined
													}
													onClick={ ( event ) => {
														if (
															event.metaKey ||
															event.ctrlKey ||
															event.shiftKey ||
															event.altKey
														) {
															return;
														}
														event.preventDefault();
														open( topic );
													} }
												>
													{ topic.title }
												</a>
											</li>
										) ) }
									</ul>
								</div>
							);
						} ) }
					</nav>
				</div>

				<div className="amc-help-main">
					{ current ? (
						<article
							className="amc-help-topic"
							aria-labelledby="amc-help-topic-title"
						>
							<Postbox
								title={
									<span
										id="amc-help-topic-title"
										ref={ headingRef }
										tabIndex={ -1 }
									>
										{ current.title }
									</span>
								}
							>
								{ current.body }
								<div className="amc-help-pager">
									{ previous ? (
										<Button
											variant="secondary"
											onClick={ () => open( previous ) }
										>
											{ sprintf(
												/* translators: %s: Help topic title. */
												__(
													'Previous: %s',
													'rtcamp-amc-copilot'
												),
												previous.title
											) }
										</Button>
									) : (
										<span />
									) }
									{ next && (
										<Button
											variant="primary"
											onClick={ () => open( next ) }
										>
											{ sprintf(
												/* translators: %s: Help topic title. */
												__(
													'Next: %s',
													'rtcamp-amc-copilot'
												),
												next.title
											) }
										</Button>
									) }
								</div>
							</Postbox>
						</article>
					) : (
						<Notice status="info" isDismissible={ false }>
							{ __(
								'No help topics match. Try another word, or clear the search.',
								'rtcamp-amc-copilot'
							) }
						</Notice>
					) }
				</div>
			</div>
		</ScreenState>
	);
}
