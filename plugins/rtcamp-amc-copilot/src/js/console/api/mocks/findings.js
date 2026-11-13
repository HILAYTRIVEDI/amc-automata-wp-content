import { daysAgo } from './helpers';
import { latestRunId } from './catalogue';

const vuln = ( id, match, installed, extra ) => ( {
	id,
	check_id: 'vuln.match',
	group: 'vulnerabilities',
	dedupe_key: `${ match.component_type }:${ match.component_slug }:${ installed }:${ match.reference }`,
	severity: match.severity,
	title: match.title,
	data: { ...match, installed_version: installed },
	status: 'open',
	note: null,
	run_id: latestRunId( 'vuln.match' ),
	resolved_at: null,
	alerted_at: null,
	...extra,
} );

/**
 * Findings fixture (`Finding` shape). Vulnerability findings carry
 * `VulnMatch::to_array() + installed_version` in `data`. Error findings use
 * the event signature as `dedupe_key`.
 */
export const findings = [
	vuln(
		301,
		{
			component_type: 'plugin',
			component_slug: 'legacy-slider',
			reference: 'CVE-2026-31841',
			title: 'Legacy Slider <= 3.1.0 - Authenticated (Contributor+) Stored Cross-Site Scripting',
			severity: 'high',
			cvss: 7.2,
			affected_range: '<= 3.1.0',
			fixed_in: '3.2.0',
			url: 'https://example.com/advisories/CVE-2026-31841',
			published_at: daysAgo( 9 ),
		},
		'3.1.0',
		{ first_seen: daysAgo( 8 ), last_seen: daysAgo( 0 ) }
	),
	vuln(
		302,
		{
			component_type: 'plugin',
			component_slug: 'gravityforms',
			reference: 'CVE-2026-29977',
			title: 'Gravity Forms < 2.9.6 - Unauthenticated PHP Object Injection',
			severity: 'critical',
			cvss: 9.8,
			affected_range: '< 2.9.6',
			fixed_in: '2.9.6',
			url: 'https://example.com/advisories/CVE-2026-29977',
			published_at: daysAgo( 4 ),
		},
		'2.9.4',
		{
			first_seen: daysAgo( 3 ),
			last_seen: daysAgo( 0 ),
			alerted_at: daysAgo( 3 ),
		}
	),
	vuln(
		303,
		{
			component_type: 'plugin',
			component_slug: 'redirection',
			reference: 'CVE-2026-27410',
			title: 'Redirection <= 5.5.2 - Open Redirect via Regex Match',
			severity: 'low',
			cvss: 4.3,
			affected_range: '<= 5.5.2',
			fixed_in: null,
			url: 'https://example.com/advisories/CVE-2026-27410',
			published_at: daysAgo( 40 ),
		},
		'5.5.2',
		{
			first_seen: daysAgo( 39 ),
			last_seen: daysAgo( 0 ),
			status: 'accepted',
			note: 'Only reachable by administrators. Revisit when a fix ships.',
		}
	),
	vuln(
		304,
		{
			component_type: 'plugin',
			component_slug: 'woocommerce',
			reference: 'CVE-2026-24012',
			title: 'WooCommerce < 10.2.0 - Insufficient Authorisation on Order Notes',
			severity: 'medium',
			cvss: 5.4,
			affected_range: '< 10.2.0',
			fixed_in: '10.2.0',
			url: 'https://example.com/advisories/CVE-2026-24012',
			published_at: daysAgo( 55 ),
		},
		'10.1.3',
		{
			first_seen: daysAgo( 52 ),
			last_seen: daysAgo( 31 ),
			status: 'resolved',
			resolved_at: daysAgo( 31 ),
			run_id: latestRunId( 'vuln.match' ) - 500,
		}
	),
	vuln(
		305,
		{
			component_type: 'core',
			component_slug: 'wordpress',
			reference: 'CVE-2026-19230',
			title: 'WordPress < 6.9.1 - Stored XSS in Navigation Block',
			severity: 'medium',
			cvss: 6.1,
			affected_range: '< 6.9.1',
			fixed_in: '6.9.1',
			url: 'https://example.com/advisories/CVE-2026-19230',
			published_at: daysAgo( 70 ),
		},
		'6.9.0',
		{
			first_seen: daysAgo( 68 ),
			last_seen: daysAgo( 60 ),
			status: 'resolved',
			resolved_at: daysAgo( 60 ),
			run_id: latestRunId( 'vuln.match' ) - 500,
		}
	),
];
