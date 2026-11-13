<?php
/**
 * Fixture: plugins_api( 'plugin_information' ) responses keyed by slug.
 *
 * `false` means "not on wp.org" (the shape the check treats as non-wp.org).
 * A closed plugin is on wp.org with an empty `versions` list.
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
	'premium-tools' => false,
	'old-closed'    => (object) [
		'last_updated' => '2026-01-01 00:00:00',
		'versions'     => [],
	],
	'stale-plugin'  => (object) [
		'last_updated' => '2024-01-01 00:00:00',
		'versions'     => [ '0.9.0' => 'https://downloads.wordpress.org/plugin/stale-plugin.0.9.0.zip' ],
	],
];
