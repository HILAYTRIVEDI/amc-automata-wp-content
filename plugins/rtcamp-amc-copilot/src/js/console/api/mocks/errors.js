import { daysAgo, hoursAgo, series } from './helpers';
import { latestRunId } from './catalogue';

const CHECK_BY_TYPE = {
	fatal: 'errors.fatal',
	warning: 'errors.warnings',
	notice: 'errors.warnings',
	deprecation: 'errors.deprecations',
	doing_it_wrong: 'errors.deprecations',
};

const SEVERITY_BY_TYPE = {
	fatal: 'critical',
	warning: 'medium',
	notice: 'low',
	deprecation: 'low',
	doing_it_wrong: 'low',
};

const rows = [
	[
		1,
		'fatal',
		'Uncaught TypeError: Argument #1 ($post) must be of type WP_Post, null given',
		'plugin:legacy-slider',
		'wp-content/plugins/legacy-slider/inc/render.php',
		88,
		17,
		6,
		2,
		'open',
	],
	[
		2,
		'fatal',
		'Allowed memory size of 268435456 bytes exhausted (tried to allocate 20480 bytes)',
		'plugin:woocommerce',
		'wp-content/plugins/woocommerce/src/Internal/Admin/Reports.php',
		412,
		3,
		22,
		264,
		'resolved',
	],
	[
		3,
		'warning',
		'Undefined array key "thumbnail"',
		'theme:amc-theme',
		'wp-content/themes/amc-theme/inc/media.php',
		57,
		5120,
		28,
		1,
		'open',
	],
	[
		4,
		'warning',
		'Attempt to read property "ID" on bool',
		'plugin:redirection',
		'wp-content/plugins/redirection/models/group.php',
		143,
		812,
		14,
		3,
		'open',
	],
	[
		5,
		'warning',
		'file_get_contents(https://api.example.com/feed): Failed to open stream: Connection timed out',
		'plugin:amc-site-tools',
		'wp-content/plugins/amc-site-tools/inc/Feed.php',
		31,
		96,
		9,
		7,
		'open',
	],
	[
		6,
		'notice',
		'Translation loading for the "gravityforms" domain was triggered too early.',
		'plugin:gravityforms',
		'wp-includes/functions.php',
		6121,
		2304,
		30,
		1,
		'open',
	],
	[
		7,
		'deprecation',
		'Function get_page_by_title is deprecated since version 6.2.0! Use WP_Query instead.',
		'plugin:amc-site-tools',
		'wp-content/plugins/amc-site-tools/inc/Pages.php',
		19,
		640,
		30,
		1,
		'open',
	],
	[
		8,
		'deprecation',
		'Creation of dynamic property AMC_Theme_Options::$cache is deprecated',
		'theme:amc-theme',
		'wp-content/themes/amc-theme/inc/class-options.php',
		74,
		1980,
		30,
		1,
		'open',
	],
	[
		9,
		'doing_it_wrong',
		'jQuery.fn.bind() is deprecated',
		'plugin:legacy-slider',
		'wp-content/plugins/legacy-slider/js/slider.js',
		12,
		25,
		5,
		24,
		'muted',
	],
];

/**
 * Errors fixture: `GET /errors` rows plus the findings behind them. Every
 * row has a `finding_id` so the console can resolve or mute it.
 */
export const errorRows = rows.map(
	( [
		n,
		type,
		message,
		source,
		file,
		line,
		count,
		firstDays,
		lastHours,
		status,
	] ) => ( {
		signature:
			`e${ String( n ).padStart( 2, '0' ) }a9c3e1d4b7f20a6c8b1e5f3d9a7c4b${ n }`.slice(
				0,
				32
			),
		type,
		message,
		source,
		file,
		line,
		count,
		first_seen: daysAgo( firstDays ),
		last_seen: hoursAgo( lastHours ),
		sparkline: series(
			30,
			Math.max( 1, Math.round( count / 30 ) ),
			Math.max( 1, Math.round( count / 60 ) ),
			n + 2
		),
		finding_id: 400 + n,
		finding_status: status,
	} )
);

export const errorFindings = rows.map(
	( [ n, type, message, , , , , firstDays, lastHours, status ] ) => ( {
		id: 400 + n,
		check_id: CHECK_BY_TYPE[ type ],
		group: 'errors',
		dedupe_key: errorRows[ n - 1 ].signature,
		severity: SEVERITY_BY_TYPE[ type ],
		title: message.slice( 0, 120 ),
		data: {},
		status,
		note: null,
		run_id: latestRunId( CHECK_BY_TYPE[ type ] ),
		first_seen: daysAgo( firstDays ),
		last_seen: hoursAgo( lastHours ),
		resolved_at: 'resolved' === status ? daysAgo( 10 ) : null,
		alerted_at: null,
	} )
);
