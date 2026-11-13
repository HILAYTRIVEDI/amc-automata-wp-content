/**
 * External dependencies
 */
const fs = require( 'fs' );
const path = require( 'path' );
const CssMinimizerPlugin = require( 'css-minimizer-webpack-plugin' );
const RemoveEmptyScriptsPlugin = require( 'webpack-remove-empty-scripts' );

/**
 * WordPress dependencies
 */
const { getAsBooleanFromENV } = require( '@wordpress/scripts/utils' );

// BrowserSync runs only in watch mode. wp-scripts injects `watch` into argv for
// `wp-scripts start` (without --no-watch), and --hot implies watching too.
const isWatch =
	process.argv.includes( '--watch' ) ||
	process.argv.includes( 'watch' ) ||
	process.argv.includes( '--hot' );

if ( isWatch ) {
	// `quiet: true` suppresses dotenv's per-run "injecting env" banner, which is
	// noisy on every rebuild in watch mode.
	require( 'dotenv' ).config( { path: '.env.local', quiet: true } );
}

// HMR (BrowserSync) master switch read from .env.local (ENABLE_HMR), defaulting
// on; only an explicit off value disables it. Mirrors is_hmr_enabled() in
// inc/Core/Assets.php so one flag controls both the BrowserSync server (here)
// and its client enqueue (PHP).
const hmrFlag = String( process.env.ENABLE_HMR || '' ).toLowerCase();
const isHmrEnabled = ! [ 'false', '0', 'no', 'off' ].includes( hmrFlag );

const DEFAULT_BS_PORT = 3003;

/**
 * Parse a TCP port from an env value, falling back when it is missing or not a
 * valid port number (1–65535).
 *
 * @param {string|undefined} value    Raw env value.
 * @param {number}           fallback Port to use when `value` is invalid.
 * @return {number} A valid port.
 */
const toPort = ( value, fallback ) => {
	// Digits only: parseInt would read '8888foo' as 8888, and Number would accept hex.
	const port = /^\d+$/.test( String( value ?? '' ) ) ? Number( value ) : NaN;
	return Number.isInteger( port ) && port >= 1 && port <= 65535
		? port
		: fallback;
};

const bsPort = toPort( process.env.BS_PORT, DEFAULT_BS_PORT );

const BROWSER_SYNC_FILES = [
	'assets/build/css/**/*.css',
	'assets/build/js/**/*.js',
	'**/*.php',
	'!vendor/**',
	'!assets/build/**/*.php',
	'!assets/build/**/*.map',
	'!assets/build/**/*.hot-update.*',
];

/**
 * Create BrowserSync only for watch mode.
 *
 * BrowserSync watches build output and PHP templates. CSS is injected in place;
 * everything else triggers a full reload. A custom host and SSL cert paths (for
 * HTTPS local sites) are read from .env.local when present.
 *
 * @return {Array} BrowserSync plugin instances.
 */
const getBrowserSyncPlugins = () => {
	if ( ! isWatch || ! isHmrEnabled ) {
		return [];
	}

	const BrowserSyncPlugin = require( 'browser-sync-webpack-plugin' );

	return [
		new BrowserSyncPlugin(
			{
				port: bsPort,
				...( process.env.WP_HOST ? { host: process.env.WP_HOST } : {} ),
				...( process.env.WP_SSL_KEY && process.env.WP_SSL_CERT
					? {
							https: {
								key: process.env.WP_SSL_KEY,
								cert: process.env.WP_SSL_CERT,
							},
						}
					: {} ),
				files: BROWSER_SYNC_FILES,
				notify: false,
				open: false,
				logSnippet: false,
				ghostMode: false,
			},
			{
				injectCss: true,
			}
		),
	];
};

const hasExperimentalModulesFlag = getAsBooleanFromENV(
	'WP_EXPERIMENTAL_MODULES'
);
let scriptConfig, moduleConfig;

if ( hasExperimentalModulesFlag ) {
	[
		scriptConfig,
		moduleConfig,
	] = require( '@wordpress/scripts/config/webpack.config' );
} else {
	scriptConfig = require( '@wordpress/scripts/config/webpack.config' );
}

// The base @wordpress/scripts config adds copy-webpack-plugin (whose class is
// named `CopyPlugin`, not `CopyWebpackPlugin` — that's just wp-scripts' import
// alias) to copy block.json/render.php from the source blocks directory into
// the output. Block compilation is owned by the dedicated `build:blocks`
// script (it scans src/blocks/ and outputs to assets/build/blocks/). Now that
// our source lives in `src/` — wp-scripts' default location — this `build:assets`
// run would otherwise re-discover the blocks and copy them into
// assets/build/js/blocks/. Strip only the copy plugin; the other block plugins
// (PhpFilePaths, manifest, dependency) have interdependencies the script/css
// build still relies on.
const sharedConfig = {
	...scriptConfig,
	watchOptions: {
		...( scriptConfig.watchOptions || {} ),
		// Ignore build output (and node_modules) so webpack doesn't watch the
		// files it emits into assets/build and rebuild in a loop — which would
		// otherwise make BrowserSync reload the browser endlessly.
		ignored: [
			'**/node_modules/**',
			path.resolve( process.cwd(), 'assets', 'build', '**' ),
		],
	},
	output: {
		path: path.resolve( process.cwd(), 'assets', 'build', 'js' ),
		filename: '[name].js',
		chunkFilename: '[name].js',
	},
	plugins: [
		...scriptConfig.plugins
			.filter( ( plugin ) => 'CopyPlugin' !== plugin.constructor.name )
			.map( ( plugin ) => {
				if ( plugin.constructor.name === 'MiniCssExtractPlugin' ) {
					plugin.options.filename = '../css/[name].css';
				}
				return plugin;
			} ),
		new RemoveEmptyScriptsPlugin(),
	],
	optimization: {
		...scriptConfig.optimization,
		splitChunks: {
			...scriptConfig.optimization.splitChunks,
		},
		minimizer: scriptConfig.optimization.minimizer.concat( [
			new CssMinimizerPlugin(),
		] ),
	},
};

/**
 * Recursively collect webpack entries from a directory.
 *
 * Walks `dir` and returns a map of entry name → absolute file path. The entry
 * name is the file's path relative to `dir`, without extension and with forward
 * slashes, so the build output mirrors the source folder structure (e.g.
 * `src/js/admin/widget.js` → entry `admin/widget` → `assets/build/js/admin/widget.js`).
 *
 * Files and directories whose names start with `_` or `.` are skipped — use the
 * `_` prefix for partials/imports that should not become their own entry.
 *
 * Edge cases to be aware of: two files differing only by extension in the same
 * directory (e.g. `foo.js` + `foo.ts`) map to the same entry name and the last
 * one walked silently wins — keep a single source file per entry. Also,
 * directory entries are read with `withFileTypes`, whose dirents don't resolve
 * symlinks, so a symlinked subdirectory is skipped rather than walked.
 *
 * @param {string}   dir              Base directory to scan (relative to cwd).
 * @param {string[]} extensions       File extensions to include (with the dot).
 * @param {string[]} [excludeDirs=[]] Directory names to skip while walking.
 * @return {Object} Map of entry name to absolute file path.
 */
const collectEntries = ( dir, extensions, excludeDirs = [] ) => {
	const entries = {};
	const root = path.resolve( process.cwd(), dir );

	if ( ! fs.existsSync( root ) ) {
		return entries;
	}

	const walk = ( current ) => {
		fs.readdirSync( current, { withFileTypes: true } ).forEach(
			( entry ) => {
				if (
					entry.name.startsWith( '_' ) ||
					entry.name.startsWith( '.' )
				) {
					return;
				}

				const fullPath = path.join( current, entry.name );

				if ( entry.isDirectory() ) {
					if ( ! excludeDirs.includes( entry.name ) ) {
						walk( fullPath );
					}
					return;
				}

				if ( ! extensions.includes( path.extname( entry.name ) ) ) {
					return;
				}

				const name = path
					.relative( root, fullPath )
					.replace( /\.[^/.]+$/, '' )
					.split( path.sep )
					.join( '/' );

				entries[ name ] = fullPath;
			}
		);
	};

	walk( root );

	return entries;
};

// Generate a webpack config which includes setup for CSS extraction.
// Recursively scans src/css (including subfolders) and extracts each stylesheet
// into a matching path under build/css.
const styles = {
	...sharedConfig,
	entry: () => collectEntries( './src/css', [ '.css', '.scss', '.sass' ] ),
	module: {
		...sharedConfig.module,
	},
	plugins: [
		...sharedConfig.plugins.filter(
			( plugin ) =>
				plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
		),
	],
};

// Recursively scans src/js (including subfolders) for script entry points. The
// `modules` directory is excluded — it is built separately as ES modules below.
const scripts = {
	...sharedConfig,
	entry: () =>
		collectEntries(
			'./src/js',
			[ '.js', '.jsx', '.ts', '.tsx' ],
			[ 'modules' ]
		),
	plugins: [ ...sharedConfig.plugins, ...getBrowserSyncPlugins() ],
};

let moduleScripts = {};
if ( hasExperimentalModulesFlag ) {
	moduleScripts = {
		...moduleConfig,
		entry: () =>
			collectEntries( './src/js/modules', [
				'.js',
				'.jsx',
				'.ts',
				'.tsx',
			] ),
		output: {
			...moduleConfig.output,
			path: path.resolve(
				process.cwd(),
				'assets',
				'build',
				'js',
				'modules'
			),
			filename: '[name].js',
			chunkFilename: '[name].js',
		},
	};
}

const customExports = [ scripts, styles ];

if ( hasExperimentalModulesFlag ) {
	customExports.push( moduleScripts );
}

module.exports = customExports;
