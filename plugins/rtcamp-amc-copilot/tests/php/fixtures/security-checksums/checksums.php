<?php
/**
 * What get_core_checksums() returns: path => md5, for an 8-file toy core.
 *
 * @package AMC
 */

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
