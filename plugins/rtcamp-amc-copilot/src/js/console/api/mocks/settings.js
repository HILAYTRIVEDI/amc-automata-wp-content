/**
 * Settings fixture (`GET /settings`, `Settings::for_rest()`), plus the
 * engineers list (`GET /access`).
 */
export const settings = {
	site_type: 'ecommerce',
	environment: 'production',
	contract_start: '2026-02-01',
	recipients: [ 'priya@example-client.com', 'oncall@rtcamp.example' ],
	digest_time: '09:00',
	immediate_alerts_per_hour: 10,
	notify_client_on_publish: true,
	smoke_urls: [
		{
			url: 'https://example-client.com/checkout/',
			required_text: 'Place order',
		},
		{
			url: 'https://staging-basic-auth.example-client.com/',
			required_text: '',
		},
	],
	loopback_auth: {
		username: '',
		has_password: false,
		encryption_available: true,
	},
	kill_switch: false,
	kill_switch_locked: false,
	checks: {
		'security.config': { enabled: true, interval: 'weekly' },
		'detect.cdn': { enabled: false, interval: '' },
	},
	retention_months: 13,
	disclaimers: { backups: '', hosting: '', domains: '', accessibility: '' },
};

export const engineers = [
	{
		id: 7,
		login: 'priya.nair',
		display_name: 'Priya Nair',
		email: 'priya@example-client.com',
	},
	{
		id: 9,
		login: 'jordan.lee',
		display_name: 'Jordan Lee',
		email: 'jordan@rtcamp.example',
	},
];

export const candidateUsers = [
	{ id: 12, name: 'Support Contractor', slug: 'support-contractor' },
	{ id: 14, name: 'Sam Okafor', slug: 'sam.okafor' },
];
