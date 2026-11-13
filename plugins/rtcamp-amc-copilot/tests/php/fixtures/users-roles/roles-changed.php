<?php
/**
 * Fixture: the same roles after capabilities changed.
 *
 * Administrator gained edit_theme_options; editor gained publish_posts and
 * lost edit_posts; subscriber is unchanged.
 *
 * @package AMC
 */

declare( strict_types = 1 );

return [
	'administrator' => [
		'name'         => 'Administrator',
		'capabilities' => [
			'edit_theme_options' => true,
			'manage_options'     => true,
			'read'               => true,
		],
	],
	'editor'        => [
		'name'         => 'Editor',
		'capabilities' => [
			'publish_posts' => true,
			'read'          => true,
		],
	],
	'subscriber'    => [
		'name'         => 'Subscriber',
		'capabilities' => [ 'read' => true ],
	],
];
