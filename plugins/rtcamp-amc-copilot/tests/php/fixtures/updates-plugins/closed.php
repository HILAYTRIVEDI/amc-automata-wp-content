<?php
/**
 * Fixture: a plugin closed on wp.org (present in neither response nor no_update).
 *
 * @package AMC
 */

declare( strict_types = 1 );

return [
	(object) [
		'last_checked' => 1793520000,
		'response'     => [],
		'no_update'    => [
			'akismet/akismet.php' => (object) [
				'slug'         => 'akismet',
				'new_version'  => '5.3.5',
				'tested'       => '6.8',
				'requires_php' => '5.2',
			],
		],
	],
	require __DIR__ . '/plugins.php',
];
