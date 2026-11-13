import { daysAgo } from './helpers';

/**
 * Reports fixture (`GET /reports`, `Report` shape) and saved fields
 * (`GET /saved-fields`).
 */
export const reports = [
	{
		id: 5120,
		title: 'Website Maintenance Report: October 2026',
		status: 'draft',
		report_id: 'AMC-EXC-2026-10',
		period_type: 'month',
		period_start: '2026-10-01',
		period_end: '2026-10-31',
		environment: 'production',
		author: { id: 7, name: 'Priya Nair' },
		modified: daysAgo( 12 ),
		edit_url: 'http://localhost/wp-admin/post.php?post=5120&action=edit',
		view_url: null,
	},
	{
		id: 5071,
		title: 'Website Maintenance Report: September 2026',
		status: 'publish',
		report_id: 'AMC-EXC-2026-09',
		period_type: 'month',
		period_start: '2026-09-01',
		period_end: '2026-09-30',
		environment: 'production',
		author: { id: 7, name: 'Priya Nair' },
		modified: daysAgo( 40 ),
		edit_url: 'http://localhost/wp-admin/post.php?post=5071&action=edit',
		view_url: null,
	},
	{
		id: 4990,
		title: 'Website Maintenance Report: Q3 2026',
		status: 'publish',
		report_id: 'AMC-EXC-2026-Q3',
		period_type: 'quarter',
		period_start: '2026-07-01',
		period_end: '2026-09-30',
		environment: 'production',
		author: { id: 9, name: 'Jordan Lee' },
		modified: daysAgo( 44 ),
		edit_url: 'http://localhost/wp-admin/post.php?post=4990&action=edit',
		view_url: null,
	},
];

export const savedFields = {
	fields: {
		overview: {
			client_name: 'Example Client Ltd.',
			maintenance_schedule: 'Monthly',
		},
		database_backups: {
			method: 'Host snapshots + offsite S3 copy',
			schedule: 'Daily',
			retention: '30 days',
		},
		hosting: {
			hosting_type: 'Managed WordPress hosting',
			control_panel: null,
		},
		domains: { expiry_auto_renewal: '2027-03-14, auto-renew on' },
		ssl: { purchased_or_free: "Free (Let's Encrypt), auto-renewal on" },
		accessibility: { defined_standard: 'WCAG 2.1 AA' },
	},
	schema: {
		overview: [ 'client_name', 'maintenance_schedule' ],
		database_backups: [ 'method', 'schedule', 'retention' ],
		hosting: [ 'hosting_type', 'control_panel' ],
		domains: [ 'expiry_auto_renewal' ],
		ssl: [ 'purchased_or_free' ],
		accessibility: [ 'defined_standard' ],
	},
};
