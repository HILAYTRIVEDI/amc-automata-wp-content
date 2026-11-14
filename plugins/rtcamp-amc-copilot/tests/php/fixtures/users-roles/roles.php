<?php
/**
 * Fixture: role definitions, wp_roles()->roles-shaped.
 *
 * Capabilities are deliberately unsorted so the check has to sort them.
 *
 * @package AMC
 */

declare( strict_types = 1 );

return [
	'administrator' => [
		'name'         => 'Administrator',
		'capabilities' => [
			'read'           => true,
			'manage_options' => true,
		],
	],
	'editor'        => [
		'name'         => 'Editor',
		'capabilities' => [
			'edit_posts' => true,
			'read'       => true,
		],
	],
	'subscriber'    => [
		'name'         => 'Subscriber',
		'capabilities' => [ 'read' => true ],
	],
];
