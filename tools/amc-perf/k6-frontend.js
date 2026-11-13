/**
 * k6 front-end performance probe for AMC Copilot.
 *
 * Hits a list of front-end URLs through a shared-iterations scenario and
 * records the p50 / p95 / p99 response times in a JSON summary that
 * tools/amc-perf/compare.sh can diff.
 *
 * Environment variables
 *   BASE_URL      Site root, no trailing slash. Default: http://localhost:8888
 *   URLS          Comma-separated paths (or absolute URLs). Default: /
 *   VUS           Virtual users. Default: 10
 *   ITERATIONS    Total requests. Default: 10000 (one request per iteration)
 *   P95_THRESHOLD Optional p95 threshold in ms; adds a k6 threshold when set.
 *   SUMMARY_OUT   JSON output path. Default: summary.json
 *   MAX_DURATION  Scenario cap. Default: 30m
 *
 * Usage:
 *   k6 run tools/amc-perf/k6-frontend.js
 *   BASE_URL=https://pilot.example.com URLS=/,/sample-page/ VUS=20 k6 run tools/amc-perf/k6-frontend.js
 *
 * The script deliberately imports nothing from jslib so it runs offline; the
 * human-readable summary is built in handleSummary.
 */

import http from 'k6/http';
import { check } from 'k6';
import exec from 'k6/execution';

function intEnv(name, fallback) {
	const raw = __ENV[name];
	if (raw === undefined || raw === '') {
		return fallback;
	}
	const value = Number.parseInt(raw, 10);
	if (!Number.isFinite(value) || value <= 0) {
		throw new Error(`${name} must be a positive integer, got "${raw}"`);
	}
	return value;
}

const BASE_URL = (__ENV.BASE_URL || 'http://localhost:8888').replace(/\/+$/, '');

const URLS = (__ENV.URLS || '/')
	.split(',')
	.map((url) => url.trim())
	.filter(Boolean);

if (URLS.length === 0) {
	throw new Error('URLS is empty; pass at least one path, for example URLS=/');
}

const VUS = intEnv('VUS', 10);
const ITERATIONS = intEnv('ITERATIONS', 10000);
const P95_THRESHOLD = __ENV.P95_THRESHOLD ? Number(__ENV.P95_THRESHOLD) : null;

export const options = {
	// Bodies are irrelevant to latency measurement; skipping them keeps memory
	// flat at 10k requests.
	discardResponseBodies: true,
	scenarios: {
		frontend: {
			executor: 'shared-iterations',
			vus: VUS,
			iterations: ITERATIONS,
			maxDuration: __ENV.MAX_DURATION || '30m',
			gracefulStop: '10s',
		},
	},
	summaryTrendStats: ['avg', 'min', 'med', 'max', 'p(50)', 'p(95)', 'p(99)'],
	thresholds: P95_THRESHOLD
		? {
				'http_req_duration': [`p(95)<${P95_THRESHOLD}`],
				'http_req_failed': ['rate<0.01'],
		  }
		: {},
};

function toUrl(path) {
	if (/^https?:\/\//i.test(path)) {
		return path;
	}
	return `${BASE_URL}${path.startsWith('/') ? '' : '/'}${path}`;
}

export default function () {
	// iterationInTest is unique across VUs for shared-iterations, so round-robin
	// over the URL list without a shared mutable counter.
	const index = exec.scenario.iterationInTest;
	const path = URLS[index % URLS.length];

	const res = http.get(toUrl(path), {
		tags: { name: path },
	});

	check(res, {
		'status is 2xx, 3xx or 4xx-not-5xx': (r) => r.status > 0 && r.status < 500,
	});
}

function round(value) {
	return typeof value === 'number' ? Math.round(value * 1000) / 1000 : null;
}

export function handleSummary(data) {
	const duration = (data.metrics.http_req_duration || {}).values || {};
	const failed = (data.metrics.http_req_failed || {}).values || {};
	const requests = (data.metrics.http_reqs || {}).values || {};
	const checks = (data.metrics.checks || {}).values || {};

	const report = {
		generatedAt: new Date().toISOString(),
		baseUrl: BASE_URL,
		urls: URLS,
		vus: VUS,
		iterations: ITERATIONS,
		requests: requests.count || 0,
		requestsPerSecond: round(requests.rate),
		httpRequestFailedRate: round(failed.rate),
		checksPassRate: round(checks.rate),
		latencyMs: {
			avg: round(duration.avg),
			min: round(duration.min),
			p50: round(duration['p(50)'] !== undefined ? duration['p(50)'] : duration.med),
			p95: round(duration['p(95)']),
			p99: round(duration['p(99)']),
			max: round(duration.max),
		},
		thresholds: P95_THRESHOLD ? { p95: P95_THRESHOLD } : null,
	};

	const lines = [
		'',
		`AMC Copilot front-end probe: ${BASE_URL}`,
		`  urls:       ${URLS.join(', ')}`,
		`  vus:        ${VUS}`,
		`  requests:   ${report.requests}`,
		`  p50:        ${report.latencyMs.p50} ms`,
		`  p95:        ${report.latencyMs.p95} ms`,
		`  p99:        ${report.latencyMs.p99} ms`,
		`  failed:     ${(report.httpRequestFailedRate * 100).toFixed(2)}%`,
		'',
	];

	const out = __ENV.SUMMARY_OUT || 'summary.json';

	return {
		[out]: JSON.stringify(report, null, 2),
		stdout: lines.join('\n'),
	};
}
