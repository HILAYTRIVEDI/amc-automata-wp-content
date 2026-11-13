import { hoursAgo, series } from './helpers';
import { latestRunId } from './catalogue';

const trend = ( base, spread, seed, failEvery = 0 ) =>
	series( 24, base, spread, seed ).map( ( ms, i ) => ( {
		at: hoursAgo( 24 - i ),
		ms,
		ok: ! failEvery || i % failEvery !== 0,
	} ) );

/**
 * Smoke fixture (`GET /smoke`, the B2 stub shape). `pass_rate_30d` is a
 * 0 to 100 percentage; `last_status` is pass | fail | loopback_blocked | null.
 */
export const smoke = {
	last_run: null,
	urls: [
		{
			url: 'https://example-client.com/',
			required_text: '',
			is_default: true,
			last_status: 'pass',
			last_http_code: 200,
			last_response_ms: 612,
			pass_rate_30d: 99.9,
			trend: trend( 640, 120, 21 ),
		},
		{
			url: 'https://example-client.com/wp-login.php',
			required_text: '',
			is_default: true,
			last_status: 'pass',
			last_http_code: 200,
			last_response_ms: 488,
			pass_rate_30d: 100,
			trend: trend( 480, 60, 22 ),
		},
		{
			url: 'https://example-client.com/product/linen-shirt/',
			required_text: '',
			is_default: true,
			last_status: 'fail',
			last_http_code: 200,
			last_response_ms: 3410,
			pass_rate_30d: 97.4,
			trend: trend( 1800, 1500, 23, 9 ),
		},
		{
			url: 'https://example-client.com/checkout/',
			required_text: 'Place order',
			is_default: false,
			last_status: 'pass',
			last_http_code: 200,
			last_response_ms: 902,
			pass_rate_30d: 98.8,
			trend: trend( 900, 200, 24 ),
		},
		{
			url: 'https://staging-basic-auth.example-client.com/',
			required_text: '',
			is_default: false,
			last_status: 'loopback_blocked',
			last_http_code: null,
			last_response_ms: null,
			pass_rate_30d: null,
			trend: [],
		},
	],
	run_id: latestRunId( 'smoke.urls' ),
};
