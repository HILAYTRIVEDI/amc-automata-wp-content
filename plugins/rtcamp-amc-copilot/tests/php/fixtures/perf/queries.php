<?php
/**
 * Fixture: QueriesRepository::grouped() rows.
 *
 * @package AMC
 */

declare( strict_types = 1 );

return [
	[
		'signature'  => str_repeat( 'a', 32 ),
		'sql_sample' => 'SELECT * FROM wp_postmeta WHERE meta_key LIKE "%seo%"',
		'caller'     => 'plugin:slow-seo',
		'count'      => 4,
		'max_ms'     => 900.0,
		'avg_ms'     => 500.0,
		'last_seen'  => '2026-11-14 11:00:00',
	],
	[
		'signature'  => str_repeat( 'b', 32 ),
		'sql_sample' => 'SELECT option_name FROM wp_options',
		'caller'     => 'plugin:slow-seo',
		'count'      => 2,
		'max_ms'     => 300.0,
		'avg_ms'     => 200.0,
		'last_seen'  => '2026-11-14 10:00:00',
	],
	[
		'signature'  => str_repeat( 'c', 32 ),
		'sql_sample' => 'SELECT * FROM wp_posts ORDER BY RAND()',
		'caller'     => 'theme:child',
		'count'      => 25,
		'max_ms'     => 180.0,
		'avg_ms'     => 120.0,
		'last_seen'  => '2026-11-14 11:30:00',
	],
	[
		'signature'  => str_repeat( 'd', 32 ),
		'sql_sample' => 'SELECT 1',
		'caller'     => 'core',
		'count'      => 1,
		'max_ms'     => 110.0,
		'avg_ms'     => 110.0,
		'last_seen'  => '2026-11-14 09:00:00',
	],
];
