<?php
/**
 * Fixture: the inventory one week later (akismet updated, hello-dolly gone,
 * new-plugin installed), for the snapshot diff.
 *
 * @package AMC
 */

declare( strict_types = 1 );

return [
	'akismet/akismet.php'             => [
		'Name'    => 'Akismet Anti-spam: Spam Protection',
		'Version' => '5.4.0',
	],
	'premium-tools/premium-tools.php' => [
		'Name'    => 'Premium Tools',
		'Version' => '2.1.0',
	],
	'new-plugin/new-plugin.php'       => [
		'Name'    => 'New Plugin',
		'Version' => '1.0.0',
	],
];
