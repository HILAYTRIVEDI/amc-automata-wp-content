#! /usr/bin/env node

/* eslint no-console: 0 */

/**
 * Plugin setup; thin wrapper that delegates to the shared init engine in
 * `@rtcamp/wp-tooling`, passing this plugin's bin/scaffold.config.js.
 *
 * Requires `npm install`. Invoke with `npm run init`. Help and the flag list
 * are printed by the engine (single source of truth); run
 * `npm run init -- --help` to see them.
 */

/**
 * External dependencies
 */
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );

/**
 * Internal dependencies
 */
const config = require( './scaffold.config' );

const argv = process.argv.slice( 2 );

// Query-only commands (help / list) skip the post-run sync-ai: nothing changed
// on disk to sync, and stray output would corrupt the `--list --json` line.
const isQuery =
	argv.includes( '--help' ) ||
	argv.includes( '-h' ) ||
	argv.includes( '--list' ) ||
	argv.includes( '--json' );

let run;
try {
	( { run } = require( '@rtcamp/wp-tooling/init' ) );
} catch ( err ) {
	if ( argv.includes( '--json' ) ) {
		// Keep the machine contract even before dependencies are installed:
		// exactly one JSON error line on stderr, exit 1.
		process.stderr.write(
			`${ JSON.stringify( {
				code: 'EENGINEMISSING',
				message:
					'Could not load @rtcamp/wp-tooling/init - run `npm install` (pilot: `npm install --install-links`).',
			} ) }\n`
		);
		process.exit( 1 );
	}
	if ( argv.includes( '--help' ) || argv.includes( '-h' ) ) {
		console.log(
			`Usage: npm run init -- [options]   (or: node bin/init.js [options])

Set up or manage this plugin via the shared @rtcamp/wp-tooling init engine.
The engine is not installed yet - run \`npm install\` (pilot:
\`npm install --install-links\`), then re-run \`npm run init -- --help\`
for the full option list.`
		);
		process.exit( 0 );
	}
	console.error(
		'\nCould not load the init engine from @rtcamp/wp-tooling.'
	);
	console.error( 'Ensure dependencies are installed (`npm install`).\n' );
	console.error( err.message );
	process.exit( 1 );
}

run( config, { root: path.resolve( __dirname, '..' ) } )
	.then( () => {
		if ( isQuery ) {
			// Engine help / list already printed. Add the one skeleton-specific
			// note the engine cannot know about, then let node exit on its own
			// so stdout flushes fully (process.exit here can truncate pipes).
			if ( argv.includes( '--help' ) || argv.includes( '-h' ) ) {
				console.log(
					'\nAfter a successful init, this plugin also runs `npm run sync-ai`.'
				);
			}
			process.exitCode = process.exitCode || 0;
			return;
		}
		// Keep the shared AI instruction files in sync after a successful init.
		// Done here (not in the npm script) so `npm run init -- <flags>` forwards
		// the flags to this script instead of to a chained `sync-ai`, and gated
		// on the exit code so a failed init does not claim a successful sync.
		if ( ! process.exitCode ) {
			try {
				execFileSync(
					process.execPath,
					[ path.join( __dirname, 'sync-ai.js' ) ],
					{ stdio: 'inherit' }
				);
			} catch ( err ) {
				console.error(
					'\ninit succeeded but `sync-ai` failed; run `npm run sync-ai` manually.'
				);
				console.error( err.message );
			}
		}
		process.exit( process.exitCode || 0 );
	} )
	.catch( ( err ) => {
		console.error( err );
		process.exit( 1 );
	} );
