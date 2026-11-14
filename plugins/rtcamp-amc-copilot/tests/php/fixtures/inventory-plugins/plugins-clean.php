<?php
/**
 * Fixture: no closed or abandoned plugins (one of them is not on wp.org).
 *
 * @package AMC
 */

declare( strict_types = 1 );

return [
	'akismet/akismet.php'             => [
		'Name'    => 'Akismet Anti-spam: Spam Protection',
		'Version' => '5.3.5',
	],
	'hello-dolly/hello.php'           => [
		'Name'    => 'Hello Dolly',
		'Version' => '1.7.2',
	],
	'premium-tools/premium-tools.php' => [
		'Name'    => 'Premium Tools',
		'Version' => '2.1.0',
	],
];
