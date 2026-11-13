<?php
/**
 * Fixture: get_core_checksums() for a toy 8-file core.
 *
 * @package AMC
 */

declare( strict_types = 1 );

return [
	'index.php'               => md5( 'index' ),
	'license.txt'             => md5( 'license' ),
	'readme.html'             => md5( 'readme' ),
	'wp-admin/index.php'      => md5( 'admin-index' ),
	'wp-includes/version.php' => md5( 'version' ),
	'wp-includes/load.php'    => md5( 'load' ),
	'wp-login.php'            => md5( 'login' ),
	'wp-content/index.php'    => md5( 'skipped' ),
	'wp-settings.php'         => md5( 'settings' ),
];
