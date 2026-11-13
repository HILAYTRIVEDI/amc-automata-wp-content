<?php
/**
 * Fixture: every wp.org plugin is up to date.
 *
 * @package AMC
 */

declare( strict_types = 1 );

return [
	(object) [
		'last_checked' => 1793520000,
		'response'     => [],
		'no_update'    => [
			'akismet/akismet.php'   => (object) [
				'slug'         => 'akismet',
				'new_version'  => '5.3.5',
				'tested'       => '6.8',
				'requires_php' => '5.2',
			],
			'hello-dolly/hello.php' => (object) [
				'slug'         => 'hello-dolly',
				'new_version'  => '1.7.2',
				'tested'       => '6.9',
				'requires_php' => '5.2',
			],
		],
	],
	require __DIR__ . '/plugins.php',
];
