<?php
/**
 * Fixture: 7.0 available (plus 6.9.2 on the current branch).
 *
 * @package AMC
 */

declare( strict_types = 1 );

declare(strict_types = 1);


return (object) [
	'last_checked' => 1793520000,
	'updates'      => [
		(object) [
			'response'      => 'upgrade',
			'current'       => '7.0',
			'version'       => '7.0',
			'locale'        => 'en_US',
			'php_version'   => '7.4',
			'mysql_version' => '5.5.5',
		],
		(object) [
			'response'      => 'upgrade',
			'current'       => '6.9.2',
			'version'       => '6.9.2',
			'locale'        => 'en_US',
			'php_version'   => '7.2.24',
			'mysql_version' => '5.5.5',
		],
	],
];
