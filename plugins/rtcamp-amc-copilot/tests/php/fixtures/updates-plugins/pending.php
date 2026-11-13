<?php
/**
 * Fixture: Akismet 5.3.6 update available.
 *
 * @package AMC
 */

declare( strict_types = 1 );

return [
	(object) [
		'last_checked' => 1793520000,
		'response'     => [
			'akismet/akismet.php'   => (object) [
				'slug'         => 'akismet',
				'new_version'  => '5.3.6',
				'tested'       => '6.9',
				'requires_php' => '7.2',
			],
			'hello-dolly/hello.php' => (object) [
				'slug'         => 'hello-dolly',
				'new_version'  => '1.7.2',
				'tested'       => '6.9',
				'requires_php' => '5.2',
			],
		],
		'no_update'    => [],
	],
	require __DIR__ . '/plugins.php',
];
