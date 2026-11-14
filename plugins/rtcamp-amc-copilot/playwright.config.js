/**
 * Playwright config for the AMC Copilot e2e specs (tests/e2e/).
 *
 * Builds on the `@wordpress/scripts` base config (admin storage state, one
 * worker, trace on failure) and points it at the wp-env development site.
 * Start that site first: `npm run build:prod && npm run wp-env start`.
 */
const path = require( 'path' );
const { defineConfig } = require( '@playwright/test' );

// Must be set before the base config is required, it reads them at load time.
process.env.WP_BASE_URL ??= 'http://localhost:8888';
process.env.WP_ARTIFACTS_PATH ??= path.join( __dirname, 'artifacts' );
process.env.STORAGE_STATE_PATH ??= path.join(
	process.env.WP_ARTIFACTS_PATH,
	'storage-states/admin.json'
);

const baseConfig = require( '@wordpress/scripts/config/playwright.config.js' );

module.exports = defineConfig( {
	...baseConfig,
	testDir: './tests/e2e',
	testMatch: '**/*.spec.js',
	globalSetup: require.resolve( './tests/e2e/global-setup.js' ),
	reporter: process.env.CI
		? [
				[ 'github' ],
				[
					'html',
					{ open: 'never', outputFolder: 'playwright-report' },
				],
			]
		: [ [ 'list' ] ],
	// wp-env is started by hand (or by CI), never by Playwright.
	webServer: undefined,
} );
