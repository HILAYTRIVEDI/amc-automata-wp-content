<?php
/**
 * Fixture: plugins_api( 'plugin_information' ) responses keyed by slug.
 *
 * What plugins_api() really returns (verified against api.wordpress.org):
 * - an object for a plugin on wp.org;
 * - a WP_Error with message "Plugin not found." for a plugin that is not on wp.org;
 * - a WP_Error with message "closed" for a plugin closed on wp.org (the API
 *   answers {"error":"closed", ...} and plugins_api() wraps it).
 * `false` (plugins_api() unavailable) is also treated as non-wp.org.
 *
 * @package AMC
 */

declare( strict_types = 1 );

return [
	'akismet'       => (object) [
		'last_updated' => '2026-10-01 00:00:00',
		'versions'     => [ '5.3.5' => 'https://downloads.wordpress.org/plugin/akismet.5.3.5.zip' ],
	],
	'hello-dolly'   => (object) [
		'last_updated' => '2025-09-01 00:00:00',
		'versions'     => [ '1.7.2' => 'https://downloads.wordpress.org/plugin/hello-dolly.1.7.2.zip' ],
	],
	'new-plugin'    => (object) [
		'last_updated' => '2026-10-20 00:00:00',
		'versions'     => [ '1.0.0' => 'https://downloads.wordpress.org/plugin/new-plugin.1.0.0.zip' ],
	],
	'premium-tools' => new \WP_Error( 'plugins_api_failed', 'Plugin not found.' ),
	'old-closed'    => new \WP_Error( 'plugins_api_failed', 'closed' ),
	'stale-plugin'  => (object) [
		'last_updated' => '2024-01-01 00:00:00',
		'versions'     => [ '0.9.0' => 'https://downloads.wordpress.org/plugin/stale-plugin.0.9.0.zip' ],
	],
];
