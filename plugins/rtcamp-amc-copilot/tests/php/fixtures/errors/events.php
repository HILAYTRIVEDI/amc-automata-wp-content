<?php
/**
 * Fixture: EventsRepository::grouped() rows keyed by event type.
 *
 * @package AMC
 */

declare( strict_types = 1 );

$row = static fn ( string $type, string $signature, string $source, string $file, int $line, int $count, string $last_seen, string $message = 'Something broke' ): array => [
	'signature'  => $signature,
	'type'       => $type,
	'message'    => $message,
	'source'     => $source,
	'file'       => $file,
	'line'       => $line,
	'count'      => $count,
	'first_seen' => substr( $last_seen, 0, 13 ) . ':00:00',
	'last_seen'  => $last_seen,
];

return [
	'fatal'          => [
		$row( 'fatal', str_repeat( 'a', 32 ), 'plugin:broken', '/srv/wp-content/plugins/broken/broken.php', 42, 7, '2026-11-14 10:20:00', 'Uncaught Error: Call to undefined function xyz()' ),
		$row( 'fatal', str_repeat( 'b', 32 ), 'theme:child', '/srv/wp-content/themes/child/functions.php', 9, 1, '2026-11-14 10:05:00', 'Allowed memory size exhausted' ),
	],
	'warning'        => [
		$row( 'warning', str_repeat( 'c', 32 ), 'plugin:noisy', '/srv/wp-content/plugins/noisy/a.php', 10, 200, '2026-11-14 10:21:00', 'Undefined array key "slug"' ),
		$row( 'warning', str_repeat( 'd', 32 ), 'plugin:noisy', '/srv/wp-content/plugins/noisy/b.php', 20, 50, '2026-11-14 10:22:00', 'Undefined variable $x' ),
		$row( 'warning', str_repeat( 'e', 32 ), 'core', '/srv/wp-includes/x.php', 5, 3, '2026-11-14 10:10:00' ),
	],
	'notice'         => [
		$row( 'notice', str_repeat( 'f', 32 ), 'plugin:noisy', '/srv/wp-content/plugins/noisy/c.php', 30, 5, '2026-11-14 10:23:00', 'Notice text' ),
	],
	'deprecation'    => [
		$row( 'deprecation', str_repeat( '1', 32 ), 'plugin:old', '/srv/wp-content/plugins/old/old.php', 77, 12, '2026-11-14 10:15:00', 'Function foo is deprecated' ),
	],
	'doing_it_wrong' => [
		$row( 'doing_it_wrong', str_repeat( '2', 32 ), 'plugin:old', '/srv/wp-content/plugins/old/old.php', 99, 4, '2026-11-14 10:16:00', 'Called incorrectly' ),
		$row( 'doing_it_wrong', str_repeat( '3', 32 ), 'theme:child', '/srv/wp-content/themes/child/inc/x.php', 3, 2, '2026-11-14 10:17:00', 'Called incorrectly' ),
	],
];
