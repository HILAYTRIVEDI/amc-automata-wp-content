// k6 front-end performance harness for AMC Copilot.
//
// Proves the Phase 1 budget (docs/phase-1-plan.md, "Performance budget on
// production"): the plugin's capture layer adds under 2 ms to front-end p95
// response time. Run the same script twice against the same target — once with
// the plugin deactivated, once activated — and compare the p95 exported to
// JSON. ./run.sh drives both runs and reports the delta.
//
// Configuration (environment variables, all optional):
//   BASE_URL         target origin, no trailing slash (default http://localhost:8888)
//   TOTAL_REQUESTS   requests per run (default 10000)
//   VUS              virtual users (default 20)
//   SLEEP_MS         think time between requests, ms (default 0)
//   HOME_URL         overrides the derived home URL
//   POST_URL         overrides the derived single-post URL
//   ARCHIVE_URL      overrides the derived archive URL
//   NOT_FOUND_URL    overrides the derived 404 URL
//   SEARCH_URL       overrides the derived search URL
//   SUMMARY_FILE     JSON output path (default amc-k6-summary.json)

import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE_URL = (__ENV.BASE_URL || 'http://localhost:8888').replace(/\/+$/, '');
const TOTAL_REQUESTS = Number(__ENV.TOTAL_REQUESTS || 10000);
const VUS = Number(__ENV.VUS || 20);
const SLEEP_MS = Number(__ENV.SLEEP_MS || 0);
const SUMMARY_FILE = __ENV.SUMMARY_FILE || 'amc-k6-summary.json';

// Query-string URLs keep the mix independent of the site's permalink
// structure. Override per URL for a target whose post/category IDs differ
// from a fresh install.
const urls = [
	{ url: __ENV.HOME_URL || `${ BASE_URL }/`, weight: 40 },
	{ url: __ENV.POST_URL || `${ BASE_URL }/?p=1`, weight: 25 },
	{ url: __ENV.ARCHIVE_URL || `${ BASE_URL }/?cat=1`, weight: 15 },
	{ url: __ENV.NOT_FOUND_URL || `${ BASE_URL }/?p=999999`, weight: 10 },
	{ url: __ENV.SEARCH_URL || `${ BASE_URL }/?s=amc`, weight: 10 },
];

const totalWeight = urls.reduce( ( sum, entry ) => sum + entry.weight, 0 );

// A 404 is the expected outcome for the 404 URL, so "failed" means 5xx or a
// network error — not any 4xx. Otherwise the no-failed-requests threshold
// would trip on the deliberate 404.
http.setResponseCallback( ( res ) => {
	if ( res.error_code ) {
		return true;
	}
	return res.status >= 500;
} );

function pickUrl() {
	let roll = Math.random() * totalWeight;
	for ( const entry of urls ) {
		roll -= entry.weight;
		if ( roll <= 0 ) {
			return entry.url;
		}
	}
	return urls[ 0 ].url;
}

export const options = {
	vus: VUS,
	iterations: TOTAL_REQUESTS,
	thresholds: {
		http_req_failed: [ 'rate==0' ],
	},
};

export default function () {
	const res = http.get( pickUrl() );
	check( res, {
		'status is 200 or 404': ( r ) => r.status === 200 || r.status === 404,
	} );
	if ( SLEEP_MS > 0 ) {
		sleep( SLEEP_MS / 1000 );
	}
}

export function handleSummary( data ) {
	const p95 = Number( data.metrics.http_req_duration.values[ 'p(95)' ].toFixed( 3 ) );
	const failedRate = data.metrics.http_req_failed.values.rate;
	const checks = data.metrics.checks ? data.metrics.checks.values : null;

	const summary = {
		base_url: BASE_URL,
		total_requests: TOTAL_REQUESTS,
		vus: VUS,
		p95_ms: p95,
		failed_rate: Number( failedRate.toFixed( 4 ) ),
		checks_passed: checks ? checks.passes : 0,
		checks_failed: checks ? checks.fails : 0,
		timestamp: new Date().toISOString(),
	};

	return {
		stdout: `p95=${ p95.toFixed( 3 ) } ms, failed=${ ( failedRate * 100 ).toFixed( 2 ) }%, requests=${ TOTAL_REQUESTS }\n`,
		[ SUMMARY_FILE ]: JSON.stringify( summary, null, 2 ),
	};
}
