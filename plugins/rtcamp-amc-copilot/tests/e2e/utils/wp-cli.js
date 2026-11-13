/**
 * WP-CLI helpers for the e2e specs, run in the wp-env `cli` container.
 *
 * Reports are generated and published through WP-CLI because building the
 * snapshot is the slow, server-side half of generation (the REST route only
 * queues it for cron). The specs then exercise the editor and the client view
 * in the browser.
 */
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );

const PLUGIN_ROOT = path.resolve( __dirname, '../../..' );

/** The non-engineer administrator the client-view spec logs in as. */
const CLIENT_USER = {
	username: 'amc_client',
	email: 'amc_client@example.com',
	password: 'amc-client-password',
};

/** Where the client administrator's logged-in session is stored. */
const CLIENT_STORAGE_STATE = path.join(
	process.env.WP_ARTIFACTS_PATH ?? path.join( PLUGIN_ROOT, 'artifacts' ),
	'storage-states/client.json'
);

/**
 * Run `wp <args>` in wp-env and return its stdout.
 *
 * @param {string[]} args WP-CLI arguments, one array item per shell word.
 * @return {string} Standard output.
 */
function wpCli( args ) {
	return execFileSync( 'npx', [ 'wp-env', 'run', 'cli', 'wp', ...args ], {
		cwd: PLUGIN_ROOT,
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
		timeout: 280_000,
	} );
}

/**
 * IDs of every `amc_report`, in any status.
 *
 * @return {number[]} Post IDs.
 */
function listReportIds() {
	const out = wpCli( [
		'post',
		'list',
		'--post_type=amc_report',
		'--post_status=any',
		'--format=ids',
	] );
	return ( out.match( /^\d+(?: \d+)*$/m )?.[ 0 ] ?? '' )
		.split( ' ' )
		.filter( Boolean )
		.map( Number );
}

/**
 * Permanently delete every report (a clean slate between runs).
 */
function deleteAllReports() {
	const ids = listReportIds();
	if ( ids.length ) {
		wpCli( [ 'post', 'delete', ...ids.map( String ), '--force' ] );
	}
}

/**
 * Permanently delete one report.
 *
 * @param {number} id Post ID.
 */
function deleteReport( id ) {
	wpCli( [ 'post', 'delete', String( id ), '--force' ] );
}

/**
 * Generate a draft report for a custom period, as the admin engineer, with the
 * snapshot built (this is what `wp amc report generate` does).
 *
 * Every spec uses its own period so reports never collide on their report ID.
 *
 * @param {string} start First day, Y-m-d.
 * @param {string} end   Last day, Y-m-d.
 * @return {number} The draft's post ID.
 */
function generateReport( start, end ) {
	const out = wpCli( [
		'amc',
		'report',
		'generate',
		'--period=custom',
		`--start=${ start }`,
		`--end=${ end }`,
		'--user=admin',
	] );
	const id = out.match( /\(post (\d+)\)/ )?.[ 1 ];
	if ( ! id ) {
		throw new Error( `Could not generate a report:\n${ out }` );
	}
	return Number( id );
}

/**
 * Publish a report from outside the editor.
 *
 * @param {number} id Post ID.
 */
function publishReport( id ) {
	wpCli( [
		'post',
		'update',
		String( id ),
		'--post_status=publish',
		'--user=admin',
	] );
}

/**
 * Set a report's title (so a spec can find it in a list).
 *
 * @param {number} id    Post ID.
 * @param {string} title New title.
 */
function setReportTitle( id, title ) {
	wpCli( [ 'post', 'update', String( id ), `--post_title=${ title }` ] );
}

/**
 * Run the queued report build now, instead of waiting for WP-Cron.
 *
 * WP-Cron may already have picked the event up from a loopback request, in
 * which case there is nothing left to run; callers poll for the result.
 */
function runQueuedBuilds() {
	try {
		wpCli( [ 'cron', 'event', 'run', 'amc_report_build' ] );
	} catch ( error ) {
		if (
			! /Invalid cron event/.test(
				String( error.stderr ?? error.message )
			)
		) {
			throw error;
		}
	}
}

module.exports = {
	CLIENT_STORAGE_STATE,
	CLIENT_USER,
	deleteAllReports,
	deleteReport,
	generateReport,
	listReportIds,
	publishReport,
	runQueuedBuilds,
	setReportTitle,
	wpCli,
};
