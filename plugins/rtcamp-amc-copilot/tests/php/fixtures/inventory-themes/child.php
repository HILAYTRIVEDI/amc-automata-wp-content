<?php
/**
 * Fixture: a real child/parent pair of themes for the parent row.
 *
 * @package AMC
 */

declare( strict_types = 1 );

$theme_root = __DIR__ . '/themes';

return [
	'child'  => new \WP_Theme( 'child', $theme_root ),
	'parent' => new \WP_Theme( 'parent', $theme_root ),
];
