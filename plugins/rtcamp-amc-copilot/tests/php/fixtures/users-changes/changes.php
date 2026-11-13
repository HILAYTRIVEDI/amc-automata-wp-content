<?php
/**
 * Fixture: ChangesRepository::query() result for `type => user`.
 *
 * Rows carry extra columns (source, old_version) that the check must drop.
 *
 * @package AMC
 */

declare( strict_types = 1 );

return [
	'items' => [
		[
			'id'          => 12,
			'action'      => 'role',
			'item'        => 'jane',
			'item_name'   => 'Jane Doe',
			'user_id'     => 4,
			'created_at'  => '2026-11-13 10:00:00',
			'source'      => 'admin',
			'old_version' => 'editor',
			'new_version' => 'administrator',
		],
		[
			'id'          => 11,
			'action'      => 'delete',
			'item'        => 'bob',
			'item_name'   => 'Bob Smith',
			'user_id'     => 7,
			'created_at'  => '2026-11-12 09:00:00',
			'source'      => 'cli',
			'old_version' => '',
			'new_version' => '',
		],
		[
			'id'          => 10,
			'action'      => 'role',
			'item'        => 'sam',
			'item_name'   => 'Sam Lee',
			'user_id'     => 9,
			'created_at'  => '2026-11-11 08:00:00',
			'source'      => 'admin',
			'old_version' => 'subscriber',
			'new_version' => 'editor',
		],
	],
	'total' => 7,
];
