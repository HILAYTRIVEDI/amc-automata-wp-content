<?php
/**
 * Fixture: FixtureProvider entries.
 *
 * @package AMC
 */

declare( strict_types = 1 );

return [
	[
		'type'         => 'plugin',
		'slug'         => 'contact-forms',
		'reference'    => 'CVE-2026-1001',
		'title'        => 'Unauthenticated SQL injection',
		'severity'     => 'critical',
		'cvss'         => 9.8,
		'affected'     => '<2.4.1',
		'fixed_in'     => '2.4.1',
		'url'          => 'https://example.test/CVE-2026-1001',
		'published_at' => '2026-10-30',
	],
	[
		'type'      => 'plugin',
		'slug'      => 'gallery-lite',
		'reference' => 'CVE-2026-2002',
		'title'     => 'Stored XSS in captions',
		'severity'  => 'medium',
		'affected'  => '>=1.0,<=1.8',
	],
	[
		'type'      => 'theme',
		'slug'      => 'twentytwenty',
		'reference' => 'CVE-2026-3003',
		'title'     => 'Open redirect',
		'severity'  => 'low',
		'affected'  => '*',
	],
];
