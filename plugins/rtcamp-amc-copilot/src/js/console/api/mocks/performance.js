import { hoursAgo, series } from './helpers';

/**
 * Performance fixture (`GET /performance`, the B2 stub shape for the trend).
 */
export const performance = {
	response_trend: series( 30, 760, 140, 31 ).map( ( avgMs, i ) => ( {
		date: new Date( Date.UTC( 2026, 9, 15 + i ) )
			.toISOString()
			.slice( 0, 10 ),
		avg_ms: avgMs,
		url_count: 5,
	} ) ),
	slow_queries: [
		{
			signature: 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
			caller: 'WP_Query->get_posts / amc-theme/inc/related.php:42',
			sql_sample:
				"SELECT p.ID FROM wp_posts p INNER JOIN wp_postmeta m ON ( p.ID = m.post_id ) WHERE m.meta_key = '_related_ids' AND m.meta_value LIKE '%1234%'",
			count: 318,
			max_ms: 1840,
			avg_ms: 620,
			last_seen: hoursAgo( 1 ),
		},
		{
			signature: 'b2c3d4e5f60718293a4b5c6d7e8f90a1',
			caller: 'woocommerce/src/Internal/DataStores/Orders/OrdersTableQuery.php:211',
			sql_sample:
				'SELECT id FROM wp_wc_orders WHERE status IN ( ... ) ORDER BY date_created_gmt DESC LIMIT 0, 100',
			count: 74,
			max_ms: 410,
			avg_ms: 220,
			last_seen: hoursAgo( 4 ),
		},
		{
			signature: 'c3d4e5f60718293a4b5c6d7e8f90a1b2',
			caller: 'amc-site-tools/inc/Feed.php:77',
			sql_sample:
				"SELECT option_value FROM wp_options WHERE option_name LIKE '_transient_amc_feed_%'",
			count: 41,
			max_ms: 180,
			avg_ms: 131,
			last_seen: hoursAgo( 9 ),
		},
	],
};
