<?php
/**
 * Fixture: drop-ins, _get_dropins()-shaped (file => [ description, active ]).
 *
 * @package AMC
 */

declare( strict_types = 1 );

return [
	'advanced-cache.php' => [ 'Advanced caching drop-in.', true ],
	'object-cache.php'   => [ 'Object cache drop-in.', 1 ],
	'maintenance.php'    => [ 'Maintenance mode drop-in.', false ],
];
