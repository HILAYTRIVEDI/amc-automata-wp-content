<?php
/**
 * Fixture: must-use plugins, get_mu_plugins()-shaped.
 *
 * @package AMC
 */

declare( strict_types = 1 );

return [
	'amc-maintenance/amc-maintenance.php' => [
		'Name'        => 'AMC Maintenance',
		'Version'     => '1.2.0',
		'Description' => 'Local maintenance helpers.',
	],
	'mu-loader.php'                       => [
		'Version' => '0.1.0',
	],
];
