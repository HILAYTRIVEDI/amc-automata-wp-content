<?php
/**
 * Canned smoke transport results.
 *
 * @package AMC
 */

return [
	'ok'        => [
		'code'    => 200,
		'body'    => '<html><body><h1>Welcome</h1></body></html>',
		'seconds' => 0.4,
		'error'   => null,
	],
	'slow'      => [
		'code'    => 200,
		'body'    => '<html><body>Welcome</body></html>',
		'seconds' => 3.4,
		'error'   => null,
	],
	'error500'  => [
		'code'    => 500,
		'body'    => 'Internal Server Error',
		'seconds' => 0.2,
		'error'   => null,
	],
	'php_error' => [
		'code'    => 200,
		'body'    => '<b>Warning</b>: Undefined variable $x in <b>/srv/wp-content/plugins/p/p.php</b> on line <b>9</b>',
		'seconds' => 0.5,
		'error'   => null,
	],
	'blocked'   => [
		'code'    => 0,
		'body'    => '',
		'seconds' => 0.01,
		'error'   => 'cURL error 7: Failed to connect to 127.0.0.1 port 443: Connection refused',
	],
	'timeout'   => [
		'code'    => 0,
		'body'    => '',
		'seconds' => 4.0,
		'error'   => 'cURL error 28: Operation timed out after 4001 milliseconds',
	],
	'wall'      => [
		'code'    => 401,
		'body'    => '',
		'seconds' => 0.1,
		'error'   => null,
	],
];
