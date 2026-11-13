/**
 * Scaffold config for plugin-elementary, consumed by bin/init.js and
 * handed to the @rtcamp/wp-tooling init engine.
 *
 * The `Project_Name` / `project-name` tokens already cover the composer package
 * and PHP namespace, so no explicit tokens are needed. Search tokens are
 * embedded verbatim; safe because the engine never search-replaces files under
 * bin/.
 *
 * Capability model
 * ----------------
 * Every optional capability is keep-or-remove at init time, presented in ONE
 * categorized "Select the capabilities to include" prompt. Removing a capability
 * deletes it ENTIRELY: its concrete classes, its `inc/Modules/<X>.php` module
 * file, and its line in `Main::CLASSES` (stripped via a per-key `wp:example:<key>`
 * marker in inc/Main.php). Kept capabilities stay as clean, working references.
 *
 * Capabilities that carry their own deps/build config (Tailwind, HMR) are
 * `features` instead, so the engine can add/remove those deps on toggle.
 */

const mainFile = ( api ) => `${ api.identity.package.split( '/' )[ 1 ] }.php`;
const tailwindConst = ( api ) =>
	`${ api.identity.constantPrefix }_FEATURES_ENABLE_TAILWIND`;

// One keep/remove capability. inc/Main.php is always a strip target (the
// Main::CLASSES line); `module` adds the module file + its class dir to the
// removal set. `strip`/`remove` add any extra files/globs (e.g. a Core file
// whose region must be cleaned, or built assets).
// A capability's footprint includes its tests: removing it must also delete
// `tests/php/<Module>Test.php`, or the suite is left referencing deleted classes.
// `tests` overrides the derived name (default `tests/php/<module>Test.php`); pass
// `[]` for a capability that ships no test.
const capability = (
	key,
	label,
	category,
	{ module, strip = [], remove = [], tests } = {}
) => ( {
	key,
	label,
	category,
	// Exposed so `--list --json` reports which inc/Modules/<Name> backs this
	// capability without deriving it from file paths.
	module: module || null,
	marker: `wp:example:${ key }`,
	strip: [ 'inc/Main.php', ...strip ],
	remove: [
		...( module
			? [ `inc/Modules/${ module }.php`, `inc/Modules/${ module }` ]
			: [] ),
		...( tests || ( module ? [ `tests/php/${ module }Test.php` ] : [] ) ),
		...remove,
	],
} );

// A keep/remove CI workflow capability. These are thin caller workflows that
// invoke the reusable workflows in rtCamp/wp-shared-workflows, so there is no
// code to strip: deselecting one simply deletes its caller file. Grouped under
// Developer Tooling. Configure a kept workflow by editing its inputs (versions,
// the pinned wp-shared-workflows ref) in the YAML.
const workflow = ( key, label, file ) => ( {
	key,
	label,
	category: 'Developer Tooling',
	module: null,
	marker: `wp:ci:${ key }`,
	strip: [],
	remove: [ `.github/workflows/${ file }` ],
} );

const fs = require( 'fs' );
const path = require( 'path' );

// plugin-elementary's own repository files, kept out of new projects. Cleanup also runs on
// --reinit, "Re-run full setup" and --clean, and a project's own CODEOWNERS,
// SECURITY.md, .gitattributes or issue forms live at these same paths, so they
// are removed on first setup only: before init writes .wp-scaffold.json.
// .gitattributes export-ignores the same paths; tests/js/starter-files.test.js
// keeps the two lists in step.
const SKELETON_REPO_FILES = [
	'tests/js/starter-files.test.js',
	'.gitattributes',
	'.github/CODEOWNERS',
	'.github/ISSUE_TEMPLATE/bug.yml',
	'.github/ISSUE_TEMPLATE/config.yml',
	'.github/ISSUE_TEMPLATE/feature.yml',
	'.github/labels.yml',
	'SECURITY.md',
];
const firstSetup = ! fs.existsSync(
	path.join( __dirname, '..', '.wp-scaffold.json' )
);

module.exports = {
	kind: 'plugin',
	vendor: 'rtcamp',

	source: {
		name: 'Project Name',
		namespace: 'Project_Name\\Features',
		package: 'rtcamp/plugin-elementary',
	},

	namespace: ( id ) => `${ id.pascalSnake }\\Features`,
	package: ( id ) => `rtcamp/${ id.kebab }-features`,

	version: '1.0.0',

	fields: [
		{ key: 'name', label: 'Plugin Name' },
		{ key: 'version', label: 'Version' },
		{ key: 'textDomain', label: 'Text Domain' },
		{ key: 'package', label: 'Package' },
		{ key: 'namespace', label: 'Namespace' },
		{ key: 'functionPrefix', label: 'Function Prefix' },
		{ key: 'constantPrefix', label: 'Constant Prefix' },
	],

	versionFiles: [
		{
			path: ( target ) => `${ target.kebab }-features.php`,
			kind: 'php-header',
		},
		{ path: 'package.json', kind: 'json', key: 'version' },
	],

	steps: { composer: true, cleanup: true, git: true, hooks: true },

	// Dep/build-config-carrying capabilities. Toggling adds/removes their deps.
	featuresDir: 'bin/features',
	features: [
		{
			key: 'tailwind',
			label: 'Tailwind CSS',
			category: 'Editor & Front-end',
			description:
				'Tailwind v4 (opt-in). Adds the entry CSS, PostCSS config and deps, and flips the ENABLE_TAILWIND constant that gates the enqueue.',
			apply: {
				files: [
					{
						from: 'tailwind/tailwind.css',
						to: 'src/css/tailwind.css',
					},
					{
						from: 'tailwind/postcss.config.js',
						to: 'postcss.config.js',
					},
				],
				devDependencies: {
					'@rtcamp/tailwind-config': '^1.1.0',
					tailwindcss: '^4.3.0',
					'@tailwindcss/postcss': '^4.3.0',
				},
			},
			onEnable: ( api ) =>
				api.setDefine( mainFile( api ), tailwindConst( api ), true ),
			onDisable: ( api ) =>
				api.setDefine( mainFile( api ), tailwindConst( api ), false ),
			detect: ( api ) =>
				true ===
				api.readDefine( mainFile( api ), tailwindConst( api ) ),
		},
		{
			key: 'hmr',
			label: 'HMR (BrowserSync live reload)',
			category: 'Developer Tooling',
			defaultOn: true,
			description:
				'Live reload in watch mode. Toggling flips ENABLE_HMR in .env.local, which webpack (BrowserSync server) and PHP (client enqueue) both honour. Default on; deps stay installed.',
			onEnable: ( api ) =>
				api.setEnv( '.env.local', 'ENABLE_HMR', 'true' ),
			onDisable: ( api ) =>
				api.setEnv( '.env.local', 'ENABLE_HMR', 'false' ),
			detect: ( api ) => {
				const value = api.readEnv( '.env.local', 'ENABLE_HMR' );
				return (
					null === value ||
					! [ 'false', '0', 'no', 'off' ].includes(
						value.toLowerCase()
					)
				);
			},
		},
	],

	// Keep/remove capabilities. Removing deletes the module + classes + its
	// Main::CLASSES line (and, for cron/blocks, cleans the coupled Core regions).
	examples: {
		marker: 'wp:example',
		groups: [
			// Content modeling.
			capability( 'post-types', 'Post Types', 'Content', {
				module: 'PostTypes',
			} ),
			capability( 'taxonomies', 'Taxonomies', 'Content', {
				module: 'Taxonomies',
			} ),

			// Editor & Front-end.
			capability( 'blocks', 'Blocks', 'Editor & Front-end', {
				module: 'Blocks',
				strip: [ 'inc/Core/Assets.php' ],
				remove: [
					'src/blocks/example-*',
					'templates/block-templates/example-*.php',
				],
			} ),
			capability( 'shortcodes', 'Shortcodes', 'Editor & Front-end', {
				module: 'Shortcodes',
			} ),

			// APIs & Automation.
			capability( 'rest', 'REST Controllers', 'APIs & Automation', {
				module: 'REST',
			} ),
			capability( 'cli', 'WP-CLI Commands', 'APIs & Automation', {
				module: 'CLI',
			} ),
			capability( 'cron', 'Cron Jobs', 'APIs & Automation', {
				module: 'Cron',
				strip: [ 'inc/Core/PluginSetup.php' ],
			} ),

			// Admin.
			capability( 'settings', 'Settings Pages', 'Admin', {
				module: 'Settings',
			} ),
			capability( 'admin', 'Admin Pages', 'Admin', { module: 'Admin' } ),
			capability( 'roles', 'User Roles', 'Admin', { module: 'Roles' } ),

			// Utilities - usage examples for the wp-primitives utility services.
			// (Logger is demonstrated in always-loaded inc/Core/PluginSetup.php, not here,
			// since it is cross-cutting and should survive any capability selection.)
			capability( 'cache', 'Cache', 'Utilities', { module: 'Cache' } ),
			capability( 'transients', 'Transients', 'Utilities', {
				module: 'Transients',
			} ),

			// Developer Tooling - the consolidated CI caller. One thin workflow
			// delegates lint/test/build (+ optional a11y) to the wp-ci.yml
			// orchestrator in rtCamp/wp-shared-workflows, with every job gated
			// on detected changes; deselecting it deletes the caller file.
			// Per-check callers remain available via `npx wp-tooling add ci/<check>`.
			// (HMR lives in this category too, as a feature.)
			workflow(
				'test-measure',
				'CI: Test & Measure (all checks)',
				'test-measure.yml'
			),
		],
	},

	// Skeleton-only repository files. A composer create-project copy never has them
	// (.gitattributes export-ignores each one); a git clone does, so init removes them.
	// Cleanup also runs on re-init and --clean, so the release paths are named for the
	// skeleton: a project's own release-please files never live there.
	cleanup: {
		targets: [
			...( firstSetup ? SKELETON_REPO_FILES : [] ),
			'.github/release-starter',
			'.github/workflows/release-starter.yml',
		],
	},

	docsUrl:
		'https://github.com/rtCamp/plugin-elementary/blob/main/docs/index.md',
	repoUrl: 'https://github.com/rtCamp/plugin-elementary',
};
