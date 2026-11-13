import { daysAgo, hoursAgo } from './helpers';

/*
 * Result of the latest run of each check: `[ status, summary, data ]`.
 *
 * `updates.core` follows the documented `Result::$data` shape of the
 * exemplar check (`CoreUpdates`). The other shapes are what the console
 * expects from lanes B1 and B2 until their checks document their own; the
 * console renders any shape generically.
 */
const plugin = (
	slug,
	name,
	version,
	active,
	source,
	lastUpdatedDays,
	extra = {}
) => ( {
	slug,
	name,
	version,
	active,
	update_source: source,
	closed: false,
	last_updated: daysAgo( lastUpdatedDays ),
	...extra,
} );

export const CHECK_RESULTS = {
	'updates.core': [
		'fail',
		'WordPress 6.9.2 is available (security release).',
		{
			current_version: '6.9.1',
			latest_version: '6.9.2',
			update_available: true,
			update_type: 'minor',
			last_checked: hoursAgo( 6 ),
			offers: [
				{
					version: '6.9.2',
					locale: 'en_US',
					php_version: '7.4',
					mysql_version: '5.5.5',
					dismissed: false,
				},
			],
		},
	],
	'updates.plugins': [
		'warn',
		'4 plugin updates available; 1 has no update source.',
		{
			updates: [
				{
					name: 'WooCommerce',
					slug: 'woocommerce',
					current_version: '10.2.1',
					new_version: '10.3.0',
					tested_up_to: '6.9',
					requires_php: '7.4',
					update_source: 'wordpress.org',
					changelog_excerpt:
						'Adds order attribution reports and fixes a checkout block regression.',
					changelog_url:
						'https://wordpress.org/plugins/woocommerce/#developers',
					compatible: 'yes',
				},
				{
					name: 'Yoast SEO',
					slug: 'wordpress-seo',
					current_version: '26.1',
					new_version: '26.3',
					tested_up_to: '6.9',
					requires_php: '7.4',
					update_source: 'wordpress.org',
					changelog_excerpt:
						'Fixes a schema output conflict with custom post types.',
					changelog_url:
						'https://wordpress.org/plugins/wordpress-seo/#developers',
					compatible: 'yes',
				},
				{
					name: 'Gravity Forms',
					slug: 'gravityforms',
					current_version: '2.9.4',
					new_version: '',
					tested_up_to: '',
					requires_php: '7.4',
					update_source: 'none',
					changelog_excerpt: '',
					changelog_url: '',
					compatible: 'unknown',
				},
				{
					name: 'Legacy Slider',
					slug: 'legacy-slider',
					current_version: '3.1.0',
					new_version: '3.2.0',
					tested_up_to: '6.5',
					requires_php: '8.2',
					update_source: 'wordpress.org',
					changelog_excerpt:
						'Drops support for PHP 7.x; adds lazy loading.',
					changelog_url:
						'https://wordpress.org/plugins/legacy-slider/#developers',
					compatible: 'no',
				},
			],
		},
	],
	'updates.themes': [
		'pass',
		'1 theme update available.',
		{
			updates: [
				{
					name: 'Twenty Twenty-Five',
					slug: 'twentytwentyfive',
					current_version: '1.2',
					new_version: '1.3',
					tested_up_to: '6.9',
					requires_php: '7.2',
					update_source: 'wordpress.org',
					changelog_url:
						'https://wordpress.org/themes/twentytwentyfive/',
				},
			],
		},
	],
	'updates.php': [
		'warn',
		'PHP 8.2 reaches end of life on 2026-12-31.',
		{
			current_version: '8.2.27',
			recommended_version: '8.3',
			supported_until: '2026-12-31',
			plugins_requiring_higher: [
				{ name: 'Legacy Slider', requires_php: '8.2' },
			],
		},
	],
	'updates.readiness': [
		'warn',
		'3 of 6 active plugins are not yet tested with WordPress 7.0.',
		{
			next_major: '7.0',
			plugins: [
				{
					name: 'WooCommerce',
					version: '10.2.1',
					tested_up_to: '6.9',
					status: 'warn',
					note: 'Tested up to 6.9; not yet declared for 7.0.',
				},
				{
					name: 'Yoast SEO',
					version: '26.1',
					tested_up_to: '7.0',
					status: 'pass',
					note: 'Declares compatibility with 7.0.',
				},
				{
					name: 'Gravity Forms',
					version: '2.9.4',
					tested_up_to: '',
					status: 'info',
					note: 'No wordpress.org listing; check manually.',
				},
				{
					name: 'Legacy Slider',
					version: '3.1.0',
					tested_up_to: '6.5',
					status: 'fail',
					note: 'Last tested with 6.5, two majors behind.',
				},
				{
					name: 'AMC Site Tools',
					version: '1.4.0',
					tested_up_to: '7.0',
					status: 'pass',
					note: 'Declares compatibility with 7.0.',
				},
				{
					name: 'Redirection',
					version: '5.5.2',
					tested_up_to: '6.9',
					status: 'warn',
					note: 'Tested up to 6.9; not yet declared for 7.0.',
				},
			],
		},
	],
	'vuln.match': [
		'fail',
		'2 open matches: 1 critical, 1 high.',
		{ matches: 5, provider_error: null },
	],
	'inventory.plugins': [
		'info',
		'8 plugins (6 active), 1 closed on wordpress.org.',
		{
			plugins: [
				plugin(
					'woocommerce',
					'WooCommerce',
					'10.2.1',
					true,
					'wordpress.org',
					12
				),
				plugin(
					'wordpress-seo',
					'Yoast SEO',
					'26.1',
					true,
					'wordpress.org',
					20
				),
				plugin(
					'gravityforms',
					'Gravity Forms',
					'2.9.4',
					true,
					'other',
					48
				),
				plugin(
					'legacy-slider',
					'Legacy Slider',
					'3.1.0',
					true,
					'wordpress.org',
					560
				),
				plugin(
					'redirection',
					'Redirection',
					'5.5.2',
					true,
					'wordpress.org',
					70
				),
				plugin(
					'amc-site-tools',
					'AMC Site Tools',
					'1.4.0',
					true,
					'other',
					9
				),
				plugin(
					'hello-dolly',
					'Hello Dolly',
					'1.7.2',
					false,
					'wordpress.org',
					1700
				),
				plugin(
					'old-gallery',
					'Old Gallery',
					'0.9.1',
					false,
					'wordpress.org',
					980,
					{ closed: true }
				),
			],
		},
	],
	'inventory.themes': [
		'info',
		'3 themes; AMC Theme is active (child of Twenty Twenty-Five).',
		{
			themes: [
				{
					name: 'AMC Theme',
					slug: 'amc-theme',
					version: '2.3.0',
					active: true,
					parent: 'twentytwentyfive',
				},
				{
					name: 'Twenty Twenty-Five',
					slug: 'twentytwentyfive',
					version: '1.2',
					active: false,
					parent: '',
				},
				{
					name: 'Twenty Twenty-Four',
					slug: 'twentytwentyfour',
					version: '1.3',
					active: false,
					parent: '',
				},
			],
		},
	],
	'inventory.mu_dropins': [
		'info',
		'2 must-use plugins, 1 drop-in.',
		{
			mu_plugins: [
				{
					name: 'Cron Control',
					file: 'cron-control.php',
					version: '3.2',
				},
				{
					name: 'AMC Loader',
					file: 'amc-loader.php',
					version: '1.0.1',
				},
			],
			dropins: [ { name: 'Advanced cache', file: 'advanced-cache.php' } ],
		},
	],
	'inventory.editor': [
		'info',
		'Block editor is used for 3 of 4 public post types.',
		{
			classic_editor_plugin: false,
			post_types: [
				{ post_type: 'post', block_editor: true },
				{ post_type: 'page', block_editor: true },
				{ post_type: 'product', block_editor: true },
				{ post_type: 'event', block_editor: false },
			],
		},
	],
	'errors.fatal': [
		'fail',
		'1 fatal signature seen in the last 24 hours.',
		{ signatures: 2 },
	],
	'errors.warnings': [
		'warn',
		'3 warning signatures, 1 new source.',
		{ signatures: 4 },
	],
	'errors.deprecations': [
		'warn',
		'3 deprecation callers.',
		{ signatures: 3 },
	],
	'smoke.urls': [
		'fail',
		'1 of 5 URLs failed: Latest product (3.4 s).',
		{ checked: 5, failed: 1 },
	],
	'security.checksums': [
		'fail',
		'1 core file modified: wp-includes/version.php.',
		{ modified: [ 'wp-includes/version.php' ], missing: [], checked: 3412 },
	],
	'security.config': [
		'warn',
		'2 configuration checks need attention.',
		{
			checks: [
				{
					label: 'File editing disabled (DISALLOW_FILE_EDIT)',
					status: 'pass',
					detail: 'DISALLOW_FILE_EDIT is true.',
				},
				{
					label: 'Debug output hidden (WP_DEBUG_DISPLAY)',
					status: 'pass',
					detail: 'WP_DEBUG_DISPLAY is false.',
				},
				{
					label: 'Authentication salts set',
					status: 'pass',
					detail: 'All eight keys and salts are defined.',
				},
				{
					label: 'No "admin" username',
					status: 'warn',
					detail: 'A user named "admin" exists.',
				},
				{
					label: 'XML-RPC disabled',
					status: 'warn',
					detail: 'xmlrpc.php responds with HTTP 200.',
				},
				{
					label: 'Uploads directory listing off',
					status: 'pass',
					detail: 'Directory listing returns HTTP 403.',
				},
			],
		},
	],
	'security.admins': [
		'warn',
		'3 administrators; 1 added in the last 7 days.',
		{
			administrators: [
				{
					login: 'admin',
					email: 'ops@example-client.com',
					registered: daysAgo( 900 ),
				},
				{
					login: 'priya.nair',
					email: 'priya@example-client.com',
					registered: daysAgo( 420 ),
				},
				{
					login: 'support-contractor',
					email: 'contractor@example.org',
					registered: daysAgo( 3 ),
				},
			],
			changes: [
				{
					login: 'support-contractor',
					change: 'added',
					at: daysAgo( 3 ),
				},
			],
		},
	],
	'ssl.certificate': [
		'pass',
		'Certificate valid for 30 more days.',
		{
			https: true,
			issuer: "Let's Encrypt R11",
			subject: 'example-client.com',
			valid_from: daysAgo( 60 ),
			expires_at: daysAgo( -30 ),
			days_left: 30,
		},
	],
	'perf.response': [
		'warn',
		'Latest product is 26.9% slower than the 4-week average.',
		{
			urls: [
				{
					url: 'https://example-client.com/',
					avg_ms: 640,
					four_week_avg_ms: 610,
					change_pct: 4.9,
				},
				{
					url: 'https://example-client.com/wp-login.php',
					avg_ms: 480,
					four_week_avg_ms: 470,
					change_pct: 2.1,
				},
				{
					url: 'https://example-client.com/product/linen-shirt/',
					avg_ms: 1840,
					four_week_avg_ms: 1450,
					change_pct: 26.9,
				},
			],
		},
	],
	'perf.database': [
		'warn',
		'Autoloaded options are 1.0 MB (over 800 KB).',
		{
			source: 'information_schema',
			total_bytes: 412000000,
			autoload_bytes: 1050000,
			transients: 1840,
			tables: [
				{ name: 'wp_postmeta', bytes: 168000000, rows: 1840221 },
				{ name: 'wp_posts', bytes: 91000000, rows: 42118 },
				{ name: 'wp_options', bytes: 38000000, rows: 9244 },
				{ name: 'wp_amc_events', bytes: 22000000, rows: 210443 },
			],
		},
	],
	'perf.cache': [
		'warn',
		'No persistent object cache.',
		{
			object_cache: {
				status: 'fail',
				detail: 'No object-cache.php drop-in.',
			},
			page_cache: {
				status: 'pass',
				detail: 'cache-control: public, max-age=600; x-cache: HIT',
			},
		},
	],
	'perf.slow_queries': [
		'warn',
		'3 slow query callers in the last 24 hours.',
		{ callers: 3 },
	],
	'health.site_health': [
		'warn',
		'1 critical and 2 recommended Site Health results.',
		{ critical: 1, recommended: 2, good: 14 },
	],
	'health.cron': [
		'pass',
		'No overdue WP-Cron events.',
		{ overdue: 0, scheduled: 18 },
	],
	'users.roles': [
		'pass',
		'No role or capability changes since the last run.',
		{ users: 14, roles: { administrator: 3, editor: 4, subscriber: 7 } },
	],
	'users.changes': [ 'info', 'Collected from user events.', { events: 2 } ],
	'hosting.platform': [
		'info',
		'Kinsta, nginx 1.27, PHP 8.2.27.',
		{
			host: 'Kinsta',
			web_server: 'nginx 1.27',
			php: '8.2.27',
			database: 'MariaDB 10.11.9',
			operating_system: 'Ubuntu 22.04',
			memory_limit: '256M',
			disk: 'Unavailable',
		},
	],
	'detect.cdn': [
		'info',
		'Cloudflare detected.',
		{ cdn: 'Cloudflare', header: 'cf-ray' },
	],
	'detect.forms': [
		'info',
		'Gravity Forms with 14 forms.',
		{
			plugin: 'Gravity Forms',
			forms: 14,
			add_ons: [ 'Stripe', 'Mailchimp' ],
		},
	],
	'detect.mailer': [
		'info',
		'WP Mail SMTP is active.',
		{ plugin: 'WP Mail SMTP', phpmailer_init_hooks: true },
	],
	'detect.analytics': [
		'info',
		'GTM and GA4 detected.',
		{ gtm: [ 'GTM-XXXXXXX' ], ga: [ 'G-AB12CD34EF' ] },
	],
	'detect.accessibility': [
		'info',
		'No accessibility tool detected.',
		{ tool: '', statement_page: '/accessibility/' },
	],
};
