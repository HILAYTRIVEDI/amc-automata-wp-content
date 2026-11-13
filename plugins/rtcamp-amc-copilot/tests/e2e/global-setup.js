/**
 * Global setup: log in as the wp-env admin, make that admin an AMC engineer,
 * create the client administrator and clear any report a previous run left.
 */
const { request } = require( '@playwright/test' );
const { RequestUtils } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	CLIENT_STORAGE_STATE,
	CLIENT_USER,
	deleteAllReports,
	wpCli,
} = require( './utils/wp-cli' );

module.exports = async function globalSetup( config ) {
	const { storageState, baseURL } = config.projects[ 0 ].use;

	const requestContext = await request.newContext( { baseURL } );
	const requestUtils = new RequestUtils( requestContext, {
		storageStatePath:
			typeof storageState === 'string' ? storageState : undefined,
	} );
	await requestUtils.setupRest();
	await requestContext.dispose();

	// `amc_manage` is a per-user capability. Plugin activation only grants it
	// to the current user, and wp-env activates the plugin as nobody.
	wpCli( [ 'user', 'add-cap', 'admin', 'amc_manage' ] );

	// A client administrator: an administrator (so `amc_view_reports`) who is
	// NOT an engineer (no `amc_manage`).
	const exists = wpCli( [
		'user',
		'list',
		`--login=${ CLIENT_USER.username }`,
		'--field=ID',
	] );
	if ( ! /^\d+$/m.test( exists ) ) {
		wpCli( [
			'user',
			'create',
			CLIENT_USER.username,
			CLIENT_USER.email,
			'--role=administrator',
			`--user_pass=${ CLIENT_USER.password }`,
		] );
	}

	deleteAllReports();

	// Log the client in once; the client spec reuses this session.
	const clientUtils = await RequestUtils.setup( {
		user: {
			username: CLIENT_USER.username,
			password: CLIENT_USER.password,
		},
		storageStatePath: CLIENT_STORAGE_STATE,
		baseURL,
	} );
	await clientUtils.setupRest();
};
