import { daysAgo, hoursAgo } from './helpers';

const c = (
	id,
	type,
	action,
	item,
	itemName,
	oldVersion,
	newVersion,
	source,
	userId,
	userName,
	createdAt
) => ( {
	id,
	type,
	action,
	item,
	item_name: itemName,
	old_version: oldVersion,
	new_version: newVersion,
	source,
	user_id: userId,
	user_display_name: userName,
	created_at: createdAt,
} );

/**
 * Changes fixture (`GET /changes`, newest first).
 */
export const changes = [
	c(
		10,
		'plugin',
		'activate',
		'amc-site-tools',
		'AMC Site Tools',
		'1.4.0',
		'1.4.0',
		'cli',
		0,
		null,
		hoursAgo( 30 )
	),
	c(
		8,
		'user',
		'role',
		'support-contractor',
		'support-contractor',
		'',
		'administrator',
		'admin',
		7,
		'Priya Nair',
		daysAgo( 3 )
	),
	c(
		1,
		'plugin',
		'update',
		'woocommerce',
		'WooCommerce',
		'10.1.3',
		'10.2.1',
		'deploy',
		0,
		null,
		daysAgo( 12 )
	),
	c(
		2,
		'plugin',
		'update',
		'wordpress-seo',
		'Yoast SEO',
		'25.9',
		'26.1',
		'admin',
		7,
		'Priya Nair',
		daysAgo( 14 )
	),
	c(
		3,
		'core',
		'update',
		'wordpress',
		'WordPress',
		'6.9.0',
		'6.9.1',
		'auto',
		0,
		null,
		daysAgo( 18 )
	),
	c(
		4,
		'plugin',
		'install',
		'redirection',
		'Redirection',
		'',
		'5.5.2',
		'admin',
		7,
		'Priya Nair',
		daysAgo( 21 )
	),
	c(
		5,
		'plugin',
		'deactivate',
		'hello-dolly',
		'Hello Dolly',
		'1.7.2',
		'1.7.2',
		'cli',
		0,
		null,
		daysAgo( 23 )
	),
	c(
		6,
		'theme',
		'update',
		'amc-theme',
		'AMC Theme',
		'2.2.4',
		'2.3.0',
		'deploy',
		0,
		null,
		daysAgo( 25 )
	),
	c(
		9,
		'plugin',
		'delete',
		'akismet',
		'Akismet',
		'5.3',
		'',
		'admin',
		7,
		'Priya Nair',
		daysAgo( 30 )
	),
	c(
		7,
		'user',
		'role',
		'jordan.lee',
		'jordan.lee',
		'administrator',
		'editor',
		'admin',
		7,
		'Priya Nair',
		daysAgo( 48 )
	),
];
