<?php
/**
 * Tests for the snapshot builder.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Reports;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Test fixtures backdate rows the repositories stamp with the current time.

use AMC\Checks\Finding;
use AMC\Checks\Result;
use AMC\Checks\Severity;
use AMC\Reports\FieldCatalogue;
use AMC\Reports\Period;
use AMC\Reports\Periods;
use AMC\Storage\ChangesRepository;
use AMC\Storage\EventsRepository;
use AMC\Storage\FindingsRepository;
use AMC\Storage\Tables;
use AMC\Tests\Support\FakeCheck;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Class SnapshotBuilderTest
 */
final class SnapshotBuilderTest extends ReportsTestCase {

	public function set_up(): void {
		parent::set_up();

		// Real checks would run here too and make the outcomes depend on the site; keep only fakes.
		add_filter(
			'amc_register_checks',
			static fn ( array $checks ): array => array_values( array_filter( $checks, static fn ( $check ): bool => $check instanceof FakeCheck ) ),
			100
		);
	}

	/**
	 * Seed everything the builder reads for October 2026.
	 */
	private function seed_october(): void {
		global $wpdb;

		$this->seed_change(
			'core',
			'update',
			'WordPress',
			'2026-10-14 09:00:00',
			[
				'old_version' => '6.9.1',
				'new_version' => '6.9.2',
			]
		);

		$this->seed_run( 'detect.cdn', [ 'cdn' => 'Cloudflare' ] );
		$this->seed_run(
			'detect.forms',
			[
				'plugins'      => [
					[
						'name'       => 'Gravity Forms',
						'slug'       => 'gravityforms',
						'form_count' => 4,
						'addons'     => [ 'Mailchimp' ],
					],
				],
				'integrations' => [ [ 'name' => 'Mailchimp' ] ],
			]
		);
		$this->seed_run( 'detect.mailer', [ 'method' => 'SMTP (WP Mail SMTP)' ] );
		$this->seed_run(
			'detect.analytics',
			[
				'gtm_ids' => [ 'GTM-ABC123' ],
				'ga_ids'  => [ 'G-XYZ789' ],
			]
		);
		$this->seed_run(
			'detect.accessibility',
			[
				'tool'           => 'UserWay',
				'statement_page' => 'https://example.test/accessibility',
			]
		);
		$this->seed_run( 'perf.cache', [ 'page_cache' => 'Detected (WP Rocket)' ] );
		$this->seed_run(
			'perf.database',
			[
				'database_size_bytes' => 52428800,
				'autoload_size_bytes' => 700000,
			]
		);
		$this->seed_run(
			'ssl.certificate',
			[
				'issuer'     => "Let's Encrypt",
				'expires_at' => '2027-01-09',
			]
		);
		$this->seed_run(
			'security.checksums',
			[
				'status'         => 'pass',
				'modified_files' => [],
			]
		);
		$this->seed_run(
			'security.config',
			[
				'checks' => [
					[
						'id'     => 'file_edit',
						'label'  => 'File editing disabled',
						'status' => 'pass',
					],
				],
			]
		);

		// Smoke: two URLs, one failing run, one loopback-blocked run (not counted).
		$this->seed_run(
			'smoke.urls',
			[
				'urls' => [
					[
						'url'         => 'https://example.test/',
						'ok'          => true,
						'response_ms' => 400,
					],
					[
						'url'         => 'https://example.test/login',
						'ok'          => true,
						'response_ms' => 200,
					],
				],
			],
			'2026-10-02 10:00:00'
		);
		$this->seed_run(
			'smoke.urls',
			[
				'urls' => [
					[
						'url'         => 'https://example.test/',
						'ok'          => false,
						'response_ms' => 800,
					],
					[
						'url'    => 'https://example.test/login',
						'status' => 'loopback_blocked',
					],
				],
			],
			'2026-10-09 10:00:00'
		);

		// Response time: two weekly buckets.
		$this->seed_run( 'perf.response', [ 'avg_ms' => 300 ], '2026-10-02 10:00:00' );
		$this->seed_run( 'perf.response', [ 'avg_ms' => 500 ], '2026-10-04 10:00:00' );
		$this->seed_run( 'perf.response', [ 'avg_ms' => 250 ], '2026-10-20 10:00:00' );

		( new EventsRepository() )->upsert_many(
			[
				[
					'type'      => 'fatal',
					'signature' => md5( 'f1' ),
					'message'   => 'Uncaught Error in foo',
					'source'    => 'plugin:foo',
					'hour'      => '2026-10-05 10:00:00',
					'count'     => 2,
				],
				[
					'type'      => 'warning',
					'signature' => md5( 'w1' ),
					'message'   => 'Undefined variable $x',
					'source'    => 'theme:twenty-child',
					'hour'      => '2026-10-06 11:00:00',
					'count'     => 30,
				],
				[
					'type'      => 'notice',
					'signature' => md5( 'n1' ),
					'message'   => 'Notice',
					'source'    => 'core',
					'hour'      => '2026-10-06 11:00:00',
					'count'     => 5,
				],
				[
					'type'      => 'deprecation',
					'signature' => md5( 'd1' ),
					'message'   => 'Function is deprecated',
					'source'    => 'plugin:bar',
					'hour'      => '2026-10-07 11:00:00',
					'count'     => 9,
				],
				[
					'type'      => 'fatal',
					'signature' => md5( 'f-outside' ),
					'message'   => 'September fatal',
					'source'    => 'core',
					'hour'      => '2026-09-30 23:00:00',
					'count'     => 100,
				],
			]
		);

		$findings = new FindingsRepository();
		$id       = $findings->insert_finding(
			new Finding(
				'plugin:foo:1.0:CVE-2026-1',
				Severity::HIGH,
				'Foo has an XSS',
				[
					'slug'      => 'foo',
					'name'      => 'Foo Plugin',
					'reference' => 'CVE-2026-1',
					'fixed_in'  => '1.1',
				]
			),
			'vuln.match',
			1
		);
		$wpdb->update( Tables::name( Tables::FINDINGS ), [ 'first_seen' => '2026-10-03 08:00:00' ], [ 'id' => $id ] );

		$old = $findings->insert_finding( new Finding( 'plugin:old:1:CVE-0', Severity::LOW, 'Old', [ 'slug' => 'old' ] ), 'vuln.match', 1 );
		$wpdb->update(
			Tables::name( Tables::FINDINGS ),
			[
				'first_seen'  => '2026-08-01 00:00:00',
				'resolved_at' => '2026-09-01 00:00:00',
				'status'      => 'resolved',
			],
			[ 'id' => $old ]
		);
	}

	public function test_every_auto_field_is_filled_from_stored_data(): void {
		$this->seed_october();

		$snapshot = $this->builder()->build( $this->october(), $this->engineer, 'cron', false );

		foreach ( FieldCatalogue::auto() as $section => $fields ) {
			foreach ( $fields as $field ) {
				$this->assertArrayHasKey( $field, $snapshot['sections'][ $section ], "{$section}.{$field} is present" );
				$this->assertNotNull( $snapshot['sections'][ $section ][ $field ], "{$section}.{$field} is filled" );
			}
		}

		$sections = $snapshot['sections'];

		$this->assertSame( 'https://example.test', $sections['overview']['website_url'] );
		$this->assertSame( 'Erin Engineer', $sections['overview']['developer_name'] );
		$this->assertSame( '2026-11-01', $sections['overview']['report_date'] );
		$this->assertSame( '6.9.1', $sections['cms']['wp_version_old'] );
		$this->assertSame( '6.9.2', $sections['cms']['wp_version_new'] );
		$this->assertSame( 'block', $sections['cms']['editor_type'] );
		$this->assertSame( 'twenty-child', $sections['theme']['active_theme']['slug'] );
		$this->assertSame( 'twentytwentyfive', $sections['theme']['parent_theme']['slug'] );
		$this->assertSame( 12, $sections['user_management']['total_users'] );
		$this->assertSame( 'WP Engine', $sections['hosting']['host'] );
		$this->assertSame( 'example.test', $sections['domains']['primary_domain'] );
		$this->assertTrue( $sections['ssl']['https'] );
		$this->assertSame( "Let's Encrypt", $sections['ssl']['issuer'] );
		$this->assertSame( '2027-01-09', $sections['ssl']['expires_at'] );
		$this->assertSame( 68, $sections['ssl']['days_left'], '68 days 18 hours, rounded down' );
		$this->assertSame( 'Cloudflare', $sections['caching_cdn']['cdn'] );
		$this->assertSame( 'Redis', $sections['caching_cdn']['object_cache'] );
		$this->assertSame( 'pass', $sections['security']['core_checksums']['status'] );
		$this->assertSame( 'Gravity Forms', $sections['forms']['form_plugins'][0]['name'] );
		$this->assertSame( [ 'GTM-ABC123' ], $sections['analytics']['gtm_ids'] );
		$this->assertSame( 'UserWay', $sections['accessibility']['third_party_tool'] );
		$this->assertSame( 52428800, $sections['performance']['database_size_bytes'] );
		$this->assertSame( 700000, $sections['performance']['autoload_size_bytes'] );
	}

	public function test_snapshot_envelope_and_engineer_fields(): void {
		$snapshot = $this->builder()->build( $this->october(), 0, 'cron', false );

		$this->assertSame( 1, $snapshot['schema_version'] );
		$this->assertSame( '2026-11-01T06:00:00Z', $snapshot['taken_at'] );
		$this->assertSame(
			[
				'type'  => 'month',
				'start' => '2026-10-01',
				'end'   => '2026-10-31',
			],
			$snapshot['period']
		);
		$this->assertSame( 'https://example.test', $snapshot['site']['url'] );
		$this->assertSame( 'North West Trust', $snapshot['site']['name'] );
		$this->assertCount( 18, $snapshot['sections'] );
		$this->assertArrayNotHasKey( 'hours_used', $snapshot['sections']['overview'], 'engineer fields are never in the snapshot' );
		$this->assertArrayNotHasKey( 'totals', $snapshot, 'monthly reports have no totals block' );
	}

	public function test_developer_name_is_the_report_author(): void {
		$snapshot = $this->builder()->build( $this->october(), $this->engineer, 'cron', false );

		$this->assertSame( 'Erin Engineer', $snapshot['sections']['overview']['developer_name'] );
	}

	public function test_php_errors_come_from_events_inside_the_period(): void {
		$this->seed_october();

		$errors = $this->builder()->build( $this->october(), 0, 'cron', false )['sections']['php_errors'];

		$this->assertSame( 2, $errors['fatal_count'], 'the September fatal is outside the period' );
		$this->assertSame( 35, $errors['warning_count'], 'warnings plus notices' );
		$this->assertSame( 9, $errors['deprecation_count'] );
		$this->assertSame( 'warning', $errors['top_signatures'][0]['type'], 'sorted by count' );
		$this->assertSame( 30, $errors['top_signatures'][0]['count'] );
		$this->assertLessThanOrEqual( 10, count( $errors['top_signatures'] ) );
	}

	public function test_smoke_and_performance_come_from_runs_in_the_period(): void {
		$this->seed_october();

		$sections = $this->builder()->build( $this->october(), 0, 'cron', false )['sections'];
		$urls     = array_column( $sections['smoke_checks']['urls'], null, 'url' );

		$this->assertSame( 2, $urls['https://example.test/']['runs'] );
		$this->assertSame( 50.0, $urls['https://example.test/']['pass_rate'] );
		$this->assertSame( 600, $urls['https://example.test/']['avg_response_ms'] );
		$this->assertSame( 1, $urls['https://example.test/login']['runs'], 'a loopback-blocked run is not a run' );
		$this->assertSame( 100.0, $urls['https://example.test/login']['pass_rate'] );

		$trend = $sections['performance']['response_trend'];

		$this->assertSame( 'Week of 1 Oct', $trend[0]['label'] );
		$this->assertSame( '2026-10-01', $trend[0]['start'] );
		$this->assertSame( 400, $trend[0]['avg_ms'], 'mean of the two runs in the first week' );
		$this->assertSame( 250, $trend[2]['avg_ms'] );
		$this->assertNull( $trend[1]['avg_ms'], 'a week without runs is a gap, not zero' );
	}

	public function test_vulnerabilities_overlapping_the_period_are_listed(): void {
		$this->seed_october();

		$vulns = $this->builder()->build( $this->october(), 0, 'cron', false )['sections']['security']['vulnerabilities'];

		$this->assertCount( 1, $vulns, 'the one resolved in September is not part of October' );
		$this->assertSame( 'Foo Plugin', $vulns[0]['component'] );
		$this->assertSame( 'CVE-2026-1', $vulns[0]['reference'] );
		$this->assertSame( 'high', $vulns[0]['severity'] );
		$this->assertSame( 'open', $vulns[0]['status'] );
		$this->assertSame( '1.1', $vulns[0]['fixed_in'] );
		$this->assertSame( '2026-10-03', $vulns[0]['first_seen'] );
		$this->assertNull( $vulns[0]['resolved_at'] );
	}

	/**
	 * ACCEPTANCE: plugin updated, added, removed and inactive lists match the change log exactly.
	 */
	public function test_plugin_lists_match_the_change_log_exactly(): void {
		// Inside October.
		$this->seed_change(
			'plugin',
			'update',
			'akismet',
			'2026-10-02 08:00:00',
			[
				'item_name'   => 'Akismet',
				'old_version' => '5.2',
				'new_version' => '5.3',
				'source'      => 'admin',
			]
		);
		$this->seed_change(
			'plugin',
			'update',
			'woocommerce',
			'2026-10-15 03:00:00',
			[
				'item_name'   => 'WooCommerce',
				'old_version' => '9.3.0',
				'new_version' => '9.4.1',
				'source'      => 'deploy',
			]
		);
		$this->seed_change(
			'plugin',
			'update',
			'akismet',
			'2026-10-20 08:00:00',
			[
				'item_name'   => 'Akismet',
				'old_version' => '5.3',
				'new_version' => '5.3.1',
				'source'      => 'cli',
			]
		);
		$this->seed_change(
			'plugin',
			'install',
			'new-form',
			'2026-10-10 12:00:00',
			[
				'item_name'   => 'New Form',
				'new_version' => '1.0',
			]
		);
		$this->seed_change(
			'plugin',
			'delete',
			'old-seo',
			'2026-10-25 12:00:00',
			[
				'item_name'   => 'Old SEO',
				'old_version' => '2.0',
			]
		);
		$this->seed_change( 'plugin', 'activate', 'hello', '2026-10-11 12:00:00', [ 'item_name' => 'Hello Dolly' ] );
		$this->seed_change(
			'theme',
			'update',
			'twenty-child',
			'2026-10-12 12:00:00',
			[
				'item_name'   => 'Twenty Child',
				'old_version' => '1.0',
				'new_version' => '1.1',
			]
		);

		// Outside October: must not appear.
		$this->seed_change( 'plugin', 'update', 'akismet', '2026-09-30 23:59:59', [ 'new_version' => '5.2' ] );
		$this->seed_change( 'plugin', 'install', 'november-plugin', '2026-11-01 00:00:00', [ 'new_version' => '1.0' ] );

		$section = $this->builder()->build( $this->october(), 0, 'cron', false )['sections']['plugins_themes'];

		$this->assertSame(
			[
				[
					'type'        => 'plugin',
					'slug'        => 'akismet',
					'name'        => 'Akismet',
					'old_version' => '5.2',
					'new_version' => '5.3',
					'date'        => '2026-10-02',
					'source'      => 'admin',
				],
				[
					'type'        => 'theme',
					'slug'        => 'twenty-child',
					'name'        => 'Twenty Child',
					'old_version' => '1.0',
					'new_version' => '1.1',
					'date'        => '2026-10-12',
					'source'      => 'admin',
				],
				[
					'type'        => 'plugin',
					'slug'        => 'woocommerce',
					'name'        => 'WooCommerce',
					'old_version' => '9.3.0',
					'new_version' => '9.4.1',
					'date'        => '2026-10-15',
					'source'      => 'deploy',
				],
				[
					'type'        => 'plugin',
					'slug'        => 'akismet',
					'name'        => 'Akismet',
					'old_version' => '5.3',
					'new_version' => '5.3.1',
					'date'        => '2026-10-20',
					'source'      => 'cli',
				],
			],
			$this->by_date( $section['updated'] ),
			'every update row of the period, none from outside it, none merged'
		);

		$this->assertSame(
			[
				[
					'type'    => 'plugin',
					'slug'    => 'new-form',
					'name'    => 'New Form',
					'version' => '1.0',
					'date'    => '2026-10-10',
				],
			],
			$section['added']
		);
		$this->assertSame(
			[
				[
					'type'    => 'plugin',
					'slug'    => 'old-seo',
					'name'    => 'Old SEO',
					'version' => '2.0',
					'date'    => '2026-10-25',
				],
			],
			$section['removed']
		);

		$this->assertSame(
			[
				[
					'slug'    => 'hello',
					'name'    => 'Hello Dolly',
					'version' => '1.7',
				],
				[
					'slug'    => 'old-seo',
					'name'    => 'Old SEO',
					'version' => '2.0',
				],
			],
			$section['inactive'],
			'installed and not active at snapshot time'
		);

		$this->assertSame(
			[
				'installed'        => 3,
				'active'           => 1,
				'updated'          => 3,
				'added'            => 1,
				'removed'          => 1,
				'inactive'         => 2,
				'themes_installed' => 3,
				'themes_updated'   => 1,
			],
			$section['counts']
		);
	}

	/**
	 * Entries sorted by date (updates mix plugins and themes in log order already).
	 *
	 * @param list<array<string, mixed>> $rows Rows.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function by_date( array $rows ): array {
		usort( $rows, static fn ( array $a, array $b ): int => $a['date'] <=> $b['date'] );

		return $rows;
	}

	public function test_dates_follow_site_time_not_utc(): void {
		// 23:30 UTC on 31 Oct is 00:30 on 1 Nov in Europe/London (GMT, after DST ends it is the same);
		// use Pacific/Auckland (UTC+13 in October) so the day really moves.
		$tz     = new DateTimeZone( 'Pacific/Auckland' );
		$period = Periods::previous( Period::TYPE_MONTH, new DateTimeImmutable( '2026-11-03 09:00:00', $tz ), null, $tz );

		// 2026-10-01 00:00 NZDT is 2026-09-30 11:00 UTC; 2026-10-31 23:59:59 NZDT is 2026-10-31 10:59:59 UTC.
		$this->seed_change( 'plugin', 'install', 'at-start', '2026-09-30 11:00:00', [ 'new_version' => '1' ] );
		$this->seed_change( 'plugin', 'install', 'before-start', '2026-09-30 10:59:59', [ 'new_version' => '1' ] );
		$this->seed_change( 'plugin', 'install', 'at-end', '2026-10-31 10:59:59', [ 'new_version' => '1' ] );
		$this->seed_change( 'plugin', 'install', 'after-end', '2026-10-31 11:00:00', [ 'new_version' => '1' ] );

		$builder  = new \AMC\Reports\SnapshotBuilder( new StubSiteFacts(), null, null, null, null, null, null, $tz, $this->clock() );
		$snapshot = $builder->build( $period, 0, 'cron', false );
		$added    = array_column( $snapshot['sections']['plugins_themes']['added'], 'date', 'slug' );

		$this->assertSame( [ 'at-start', 'at-end' ], array_keys( $added ) );
		$this->assertSame( '2026-10-01', $added['at-start'] );
		$this->assertSame( '2026-10-31', $added['at-end'] );
		$this->assertSame( '2026-10-01', $snapshot['period']['start'] );
	}

	public function test_user_changes_in_the_period(): void {
		$this->seed_change( 'user', 'install', 'newhire', '2026-10-03 10:00:00', [ 'new_version' => 'editor' ] );
		$this->seed_change( 'user', 'delete', 'leaver', '2026-10-04 10:00:00' );
		$this->seed_change( 'user', 'access_grant', 'engineer1', '2026-10-05 10:00:00' );
		$this->seed_change( 'user', 'install', 'last-month', '2026-09-03 10:00:00' );

		$users = $this->builder()->build( $this->october(), 0, 'cron', false )['sections']['user_management'];

		$this->assertSame(
			[
				[
					'login' => 'newhire',
					'role'  => 'editor',
					'date'  => '2026-10-03',
				],
			],
			$users['users_added']
		);
		$this->assertSame(
			[
				[
					'login' => 'leaver',
					'date'  => '2026-10-04',
				],
			],
			$users['users_removed']
		);
		$this->assertSame( 'boss', $users['administrators'][0]['login'] );
	}

	public function test_core_version_falls_back_to_the_current_version_without_changes(): void {
		$cms = $this->builder()->build( $this->october(), 0, 'cron', false )['sections']['cms'];

		$this->assertSame( '6.9.2', $cms['wp_version_old'] );
		$this->assertSame( '6.9.2', $cms['wp_version_new'] );
	}

	public function test_core_version_uses_later_updates_for_a_past_period(): void {
		// Period had no core update, but one came after it: the version DURING the period was its old version.
		$this->seed_change(
			'core',
			'update',
			'WordPress',
			'2026-11-20 09:00:00',
			[
				'old_version' => '6.9.1',
				'new_version' => '6.9.2',
			]
		);

		$cms = $this->builder()->build( $this->october(), 0, 'cron', false )['sections']['cms'];

		$this->assertSame( '6.9.1', $cms['wp_version_old'] );
		$this->assertSame( '6.9.1', $cms['wp_version_new'] );
	}

	public function test_a_missing_check_value_is_null_not_an_error(): void {
		$snapshot = $this->builder()->build( $this->october(), 0, 'cron', false );

		$this->assertNull( $snapshot['sections']['caching_cdn']['cdn'] );
		$this->assertNull( $snapshot['sections']['ssl']['issuer'] );
		$this->assertNull( $snapshot['sections']['smoke_checks']['urls'] );
		$this->assertNull( $snapshot['sections']['performance']['response_trend'] );
	}

	public function test_fresh_checks_that_are_not_registered_are_skipped(): void {
		$builder  = $this->builder();
		$snapshot = $builder->build( $this->october(), 0, 'cron', true );

		$this->assertNotEmpty( $snapshot );

		foreach ( $builder->refreshed() as $check_id => $outcome ) {
			$this->assertSame( 'skipped', $outcome, "{$check_id}: not registered in this test" );
		}

		$this->assertArrayHasKey( 'inventory.plugins', $builder->refreshed() );
		$this->assertArrayHasKey( 'detect.analytics', $builder->refreshed() );
	}

	public function test_fresh_run_of_registered_checks_happens_before_the_change_log_is_read(): void {
		// A stand-in for inventory.plugins whose run logs a deploy-time update, as the real diff does.
		$inventory = new FakeCheck(
			'inventory.plugins',
			static function (): Result {
				( new ChangesRepository() )->insert(
					[
						'type'        => 'plugin',
						'action'      => 'update',
						'item'        => 'deployed',
						'item_name'   => 'Deployed Plugin',
						'old_version' => '1.0',
						'new_version' => '1.1',
						'source'      => 'deploy',
						'created_at'  => '2026-10-31 22:00:00',
					]
				);

				return Result::pass( 'ok', [ 'plugins' => 3 ] );
			}
		);
		$detect    = new FakeCheck( 'detect.cdn', Result::pass( 'ok', [ 'cdn' => 'Fastly' ] ) );
		$this->register_checks( $inventory, $detect );

		$builder  = $this->builder();
		$snapshot = $builder->build( $this->october(), 0, 'cron', true );

		$this->assertSame( 'ran', $builder->refreshed()['inventory.plugins'] );
		$this->assertSame( 'ran', $builder->refreshed()['detect.cdn'] );
		$this->assertSame( 'skipped', $builder->refreshed()['detect.mailer'] );
		$this->assertCount( 1, $inventory->contexts );

		$this->assertSame( 'deployed', $snapshot['sections']['plugins_themes']['updated'][0]['slug'] );
		$this->assertSame( 'deploy', $snapshot['sections']['plugins_themes']['updated'][0]['source'] );
		$this->assertSame( 'Fastly', $snapshot['sections']['caching_cdn']['cdn'], 'the fresh run is what the builder reads' );
	}

	public function test_a_check_that_throws_does_not_break_the_snapshot(): void {
		$this->register_checks( new FakeCheck( 'detect.forms', new \RuntimeException( 'boom' ) ) );

		$builder  = $this->builder();
		$snapshot = $builder->build( $this->october(), 0, 'cron', true );

		$this->assertSame( 'ran', $builder->refreshed()['detect.forms'] );
		$this->assertNull( $snapshot['sections']['forms']['form_plugins'] );
	}

	public function test_quarterly_reports_add_totals_and_a_monthly_trend(): void {
		$tz     = new DateTimeZone( 'UTC' );
		$period = Periods::previous( Period::TYPE_QUARTER, new DateTimeImmutable( '2026-11-03 09:00:00', $tz ), null, $tz );

		$this->assertSame( '2026-07-01', $period->start()->format( 'Y-m-d' ) );

		$this->seed_change( 'plugin', 'update', 'a', '2026-07-10 10:00:00', [ 'new_version' => '2' ] );
		$this->seed_change( 'plugin', 'update', 'b', '2026-08-10 10:00:00', [ 'new_version' => '2' ] );
		$this->seed_change( 'plugin', 'update', 'c', '2026-08-11 10:00:00', [ 'new_version' => '2' ] );
		$this->seed_change( 'plugin', 'install', 'd', '2026-09-30 23:00:00', [ 'new_version' => '1' ] );
		$this->seed_change( 'plugin', 'delete', 'e', '2026-09-01 00:00:00', [ 'old_version' => '1' ] );
		$this->seed_change( 'theme', 'update', 't', '2026-09-02 00:00:00', [ 'new_version' => '2' ] );

		( new EventsRepository() )->upsert_many(
			[
				[
					'type'      => 'fatal',
					'signature' => md5( 'q1' ),
					'hour'      => '2026-07-05 10:00:00',
					'count'     => 1,
				],
				[
					'type'      => 'fatal',
					'signature' => md5( 'q2' ),
					'hour'      => '2026-09-05 10:00:00',
					'count'     => 3,
				],
				[
					'type'      => 'deprecation',
					'signature' => md5( 'q3' ),
					'hour'      => '2026-08-05 10:00:00',
					'count'     => 7,
				],
			]
		);

		$this->seed_run( 'perf.response', [ 'avg_ms' => 300 ], '2026-07-15 10:00:00' );
		$this->seed_run( 'perf.response', [ 'avg_ms' => 500 ], '2026-07-16 10:00:00' );
		$this->seed_run( 'perf.response', [ 'avg_ms' => 900 ], '2026-09-20 10:00:00' );

		$snapshot = $this->builder()->build( $period, 0, 'cron', false );

		$this->assertSame(
			[
				'plugins_updated'          => 3,
				'plugins_added'            => 1,
				'plugins_removed'          => 1,
				'themes_updated'           => 1,
				'core_updates'             => 0,
				'users_added'              => 0,
				'users_removed'            => 0,
				'fatal'                    => 4,
				'warning'                  => 0,
				'deprecation'              => 7,
				'vulnerabilities_found'    => 0,
				'vulnerabilities_resolved' => 0,
			],
			$snapshot['totals']
		);

		$this->assertSame( [ 'Jul 2026', 'Aug 2026', 'Sep 2026' ], array_column( $snapshot['monthly'], 'label' ) );
		$this->assertSame( [ 1, 2, 0 ], array_column( $snapshot['monthly'], 'plugins_updated' ) );
		$this->assertSame( [ 1, 0, 3 ], array_column( $snapshot['monthly'], 'fatal' ) );

		$trend = $snapshot['sections']['performance']['response_trend'];

		$this->assertSame( [ 'Jul 2026', 'Aug 2026', 'Sep 2026' ], array_column( $trend, 'label' ), 'monthly points for a quarter' );
		$this->assertSame( [ 400, null, 900 ], array_column( $trend, 'avg_ms' ) );
		$this->assertSame( [ '2026-07-01', '2026-08-01', '2026-09-01' ], array_column( $trend, 'start' ) );
	}

	public function test_annual_reports_have_twelve_monthly_points(): void {
		$tz     = new DateTimeZone( 'UTC' );
		$period = Periods::previous( Period::TYPE_YEAR, new DateTimeImmutable( '2027-01-02 09:00:00', $tz ), null, $tz );

		$this->seed_run( 'perf.response', [ 'avg_ms' => 100 ], '2026-03-15 10:00:00' );

		$snapshot = $this->builder()->build( $period, 0, 'cron', false );

		$this->assertCount( 12, $snapshot['monthly'] );
		$this->assertCount( 12, $snapshot['sections']['performance']['response_trend'] );
		$this->assertSame( 100, $snapshot['sections']['performance']['response_trend'][2]['avg_ms'] );
		$this->assertArrayHasKey( 'totals', $snapshot );
	}

	public function test_saved_fields_are_filled_into_the_snapshot(): void {
		update_option(
			'amc_saved_fields',
			[
				'overview'         => [ 'client_name' => 'Acme Ltd' ],
				'database_backups' => [ 'backup_method' => 'UpdraftPlus' ],
			],
			false
		);

		$sections = $this->builder()->build( $this->october(), 0, 'cron', false )['sections'];

		$this->assertSame( 'Acme Ltd', $sections['overview']['client_name'] );
		$this->assertSame( 'UpdraftPlus', $sections['database_backups']['backup_method'] );
		$this->assertNull( $sections['overview']['maintenance_schedule'], 'unset saved fields stay null' );
	}

	public function test_snapshot_is_json_encodable(): void {
		$this->seed_october();

		$json = wp_json_encode( $this->builder()->build( $this->october(), 0, 'cron', false ) );

		$this->assertIsString( $json );
		$this->assertSame( 18, count( json_decode( $json, true )['sections'] ) );
	}
}
