<?php
/**
 * Fixture: 6.9.2 security release available.
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
			'current'       => '6.9.2',
			'version'       => '6.9.2',
			'locale'        => 'en_US',
			'php_version'   => '7.2.24',
			'mysql_version' => '5.5.5',
		],
		(object) [
			'response' => 'autoupdate',
			'current'  => '6.9.2',
			'version'  => '6.9.2',
			'locale'   => 'en_US',
		],
	],
];
