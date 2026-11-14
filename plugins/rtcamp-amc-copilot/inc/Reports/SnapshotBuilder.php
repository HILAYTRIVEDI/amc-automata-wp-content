<?php
/**
 * Builds the report snapshot for a period.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports;

use AMC\Checks\Registry;
use AMC\Core\Settings;
use AMC\Runner\Runner;
use AMC\Storage\ChangesRepository;
use AMC\Storage\EventsRepository;
use AMC\Storage\FindingsRepository;
use AMC\Storage\RunsRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Class SnapshotBuilder
 *
 * Produces the JSON-ready snapshot (SnapshotSchema format) for a Period from:
 *
 *   - amc_changes in the period (plugin, theme, core and user changes),
 *   - amc_events (error counts and top signatures),
 *   - amc_findings (vulnerabilities that overlap the period),
 *   - amc_runs (smoke and response history in the period; the latest run of the
 *     detection, hosting, security, SSL and performance checks),
 *   - a FRESH run of the inventory and detection checks (refresh()), which
 *     also lets `inventory.plugins` log deploy-time version changes into
 *     amc_changes BEFORE the change log is read, so the lists are complete,
 *   - WordPress core reads through SiteFacts, as the fallback for any value
 *     a check has not stored.
 *
 * Plugin updated / added / removed lists are the change log, row for row
 * (acceptance criterion): each `amc_changes` row of type plugin or theme in the
 * period becomes one list entry, nothing is merged or invented. `inactive` is
 * the live inventory at snapshot time (installed and not active).
 *
 * Quarterly, annual and long custom periods add a top-level `totals` object
 * (period totals) and `monthly` list (one entry per calendar month), and the
 * Performance trend uses monthly points. Monthly reports use weekly points.
 * Each `monthly` entry also carries the month's Performance figures:
 * `avg_response_ms` (mean of that month's perf.response runs) and
 * `database_size_bytes` / `autoload_size_bytes` (from the month's LAST
 * perf.database run, as a size is a level, not a sum). A month with no
 * matching run holds null, never 0.
 *
 * Run data the builder reads (every key is optional; a missing one falls back
 * to a WordPress read, or null, which the schema allows). When a check lands
 * with different key names, adjust the pick() lists in the matching method:
 *
 *   hosting.platform    host, server_type|os, web_server|server_software,
 *                       mysql_version|db_version, php_version, memory_limit
 *   detect.cdn          cdn|provider|name
 *   detect.forms        plugins[{name, slug, form_count, addons}], integrations[{name}|string]
 *   detect.mailer       method|sending_method|mailer
 *   detect.analytics    gtm_ids[], ga_ids[] (+ ga4_ids[], ua_ids[] merged in)
 *   detect.accessibility tool|third_party_tool, statement_page|statement_url
 *   perf.cache          page_cache, object_cache (string or bool)
 *   perf.database       database_size_bytes|total_size_bytes, autoload_size_bytes
 *   ssl.certificate     issuer, expires_at|valid_to (date or timestamp), days_left
 *   security.checksums  status, modified_files|modified (list)
 *   security.config     checks[{id, label, status}]
 *   smoke.urls          urls[{url, ok:bool | status:'pass'|'fail'|'loopback_blocked', response_ms|ms}]
 *   perf.response       avg_ms | urls[{response_ms|ms}]
 *   vuln.match          findings (FindingsRepository), data: name?, slug, reference, fixed_in
 *   users.changes       amc_changes rows type=user, action=install (added) | delete (removed)
 *
 * @since 0.1.0
 */
final class SnapshotBuilder {

	/**
	 * Checks run FRESH before every snapshot (skipped when not registered).
	 */
	public const FRESH_CHECKS = [
		'inventory.plugins',
		'inventory.themes',
		'inventory.mu_dropins',
		'inventory.editor',
		'hosting.platform',
		'detect.cdn',
		'detect.forms',
		'detect.mailer',
		'detect.analytics',
		'detect.accessibility',
	];

	/**
	 * Most pages read from a paginated repository query (500 rows each).
	 */
	private const MAX_PAGES = 24;

	/**
	 * A custom period longer than this many days gets monthly trend points.
	 */
	private const WEEKLY_MAX_DAYS = 62;

	/**
	 * Check ids that were run, skipped or failed in the last refresh().
	 *
	 * @var array<string, string> check id => 'ran' | 'skipped' | error code.
	 */
	private array $refreshed = [];

	/**
	 * Site timezone.
	 *
	 * @var DateTimeZone
	 */
	private DateTimeZone $tz;

	/**
	 * Constructor.
	 *
	 * @param SiteFacts|null          $facts    WordPress reads.
	 * @param Runner|null             $runner   Runner for the fresh checks.
	 * @param Registry|null           $registry Check registry.
	 * @param RunsRepository|null     $runs     Runs.
	 * @param FindingsRepository|null $findings Findings.
	 * @param ChangesRepository|null  $changes  Change log.
	 * @param EventsRepository|null   $events   Captured events.
	 * @param DateTimeZone|null       $tz       Site timezone (default: wp_timezone()).
	 * @param callable|null           $now      Returns the current DateTimeImmutable (test seam).
	 */
	public function __construct(
		private ?SiteFacts $facts = null,
		private ?Runner $runner = null,
		private ?Registry $registry = null,
		private ?RunsRepository $runs = null,
		private ?FindingsRepository $findings = null,
		private ?ChangesRepository $changes = null,
		private ?EventsRepository $events = null,
		?DateTimeZone $tz = null,
		private mixed $now = null
	) {
		$this->facts    ??= new SiteFacts();
		$this->registry ??= new Registry();
		$this->runner   ??= new Runner( $this->registry );
		$this->runs     ??= new RunsRepository();
		$this->findings ??= new FindingsRepository();
		$this->changes  ??= new ChangesRepository();
		$this->events   ??= new EventsRepository();
		$this->tz         = $tz ?? wp_timezone();
	}

	/**
	 * Build the snapshot for a period.
	 *
	 * @param Period $period    Reporting window (site time).
	 * @param int    $author_id Report author (0 = the default engineer).
	 * @param string $trigger   Runner trigger for the fresh checks: 'cron' | 'cli' | 'manual'.
	 * @param bool   $fresh     Run the inventory and detection checks first.
	 *
	 * @return array<string, mixed> SnapshotSchema format.
	 */
	public function build( Period $period, int $author_id = 0, string $trigger = 'cron', bool $fresh = true ): array {
		$this->refreshed = $fresh ? $this->refresh( $trigger ) : [];

		$now         = $this->now();
		$environment = Settings::environment();
		$snapshot    = SnapshotSchema::empty_snapshot();

		$snapshot['taken_at'] = $now->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
		$snapshot['period']   = [
			'type'  => $period->type(),
			'start' => $period->start()->format( 'Y-m-d' ),
			'end'   => $period->end()->format( 'Y-m-d' ),
		];
		$snapshot['site']     = [
			'url'         => $this->facts->home_url(),
			'name'        => $this->facts->site_name(),
			'blog_id'     => $this->facts->blog_id(),
			'environment' => $environment,
		];

		$changes = $this->period_changes( $period );
		$events  = $this->period_events( $period );
		$vulns   = $this->vulnerabilities( $period );

		$sections = [
			'overview'        => $this->overview( $now, $author_id ),
			'cms'             => $this->cms( $period ),
			'theme'           => $this->theme(),
			'plugins_themes'  => $this->plugins_themes( $changes ),
			'user_management' => $this->user_management( $period, $changes ),
			'hosting'         => $this->hosting(),
			'domains'         => $this->domains(),
			'ssl'             => $this->ssl( $now ),
			'caching_cdn'     => $this->caching_cdn(),
			'security'        => $this->security( $vulns ),
			'forms'           => $this->forms(),
			'analytics'       => $this->analytics(),
			'accessibility'   => $this->accessibility(),
			'php_errors'      => $this->php_errors( $events ),
			'smoke_checks'    => $this->smoke_checks( $period ),
			'performance'     => $this->performance( $period ),
		];

		foreach ( $sections as $key => $values ) {
			$snapshot['sections'][ $key ] = array_replace( $snapshot['sections'][ $key ], $values );
		}

		// Saved fields: the carried-forward values (or the latest published report's).
		foreach ( SavedFields::for_new_report( $environment ) as $section => $fields ) {
			foreach ( $fields as $field => $value ) {
				$snapshot['sections'][ $section ][ $field ] = $value;
			}
		}

		if ( $this->wants_totals( $period ) ) {
			$snapshot['totals']  = $this->totals( $changes, $events, $vulns, $period );
			$snapshot['monthly'] = $this->monthly( $period, $changes );
		}

		/**
		 * Filters a freshly built snapshot before it is stored.
		 *
		 * @param array<string, mixed> $snapshot SnapshotSchema format.
		 * @param Period               $period   Reporting window.
		 */
		return (array) apply_filters( 'amc_report_snapshot', $snapshot, $period );
	}

	/**
	 * Run the inventory and detection checks now.
	 *
	 * A check that is not registered (another lane has not merged), disabled,
	 * locked or blocked by the kill switch is skipped, never an error: the
	 * builder then uses that check's last stored run.
	 *
	 * @param string $trigger 'cron' | 'cli' | 'manual'.
	 *
	 * @return array<string, string> check id => 'ran' | 'skipped' | error code.
	 */
	public function refresh( string $trigger = 'cron' ): array {
		$result = [];

		foreach ( self::FRESH_CHECKS as $check_id ) {
			if ( ! $this->registry->has( $check_id ) ) {
				$result[ $check_id ] = 'skipped';
				continue;
			}

			$run = $this->runner->run( $check_id, $trigger );

			$result[ $check_id ] = $run instanceof \WP_Error ? $run->get_error_code() : 'ran';
		}

		return $result;
	}

	/**
	 * What the last build() did with each fresh check.
	 *
	 * @return array<string, string>
	 */
	public function refreshed(): array {
		return $this->refreshed;
	}

	// Sections.

	/**
	 * Overview (auto fields).
	 *
	 * @param DateTimeImmutable $now       Now.
	 * @param int               $author_id Author.
	 *
	 * @return array<string, mixed>
	 */
	private function overview( DateTimeImmutable $now, int $author_id ): array {
		$author = get_userdata( $author_id > 0 ? $author_id : $this->facts->default_author() );

		return [
			'website_url'    => $this->facts->home_url(),
			'developer_name' => $author instanceof \WP_User ? $author->display_name : null,
			'report_date'    => $now->setTimezone( $this->tz )->format( 'Y-m-d' ),
		];
	}

	/**
	 * CMS (auto fields).
	 *
	 * @param Period $period Period.
	 *
	 * @return array<string, mixed>
	 */
	private function cms( Period $period ): array {
		$all = $this->all_core_updates();

		return [
			'wp_version_old' => $this->core_version_at( $all, $this->utc( $period->start() ) ),
			'wp_version_new' => $this->core_version_at( $all, $this->utc( $period->end()->modify( '+1 second' ) ) ),
			'editor_type'    => $this->facts->editor_type(),
			'is_multisite'   => $this->facts->is_multisite(),
		];
	}

	/**
	 * Theme (auto fields).
	 *
	 * @return array<string, mixed>
	 */
	private function theme(): array {
		$theme = $this->facts->active_theme();

		return [
			'active_theme' => $theme['active'],
			'parent_theme' => $theme['parent'],
		];
	}

	/**
	 * Plugins and themes (auto fields): the change log and the live inventory.
	 *
	 * @param array<string, list<array<string, mixed>>> $changes Changes by type.
	 *
	 * @return array<string, mixed>
	 */
	private function plugins_themes( array $changes ): array {
		$updated = [];
		$added   = [];
		$removed = [];

		foreach ( array_merge( $changes['plugin'], $changes['theme'] ) as $row ) {
			$type = (string) $row['type'];
			$base = [
				'type' => $type,
				'slug' => (string) $row['item'],
				'name' => $this->change_name( $row ),
			];

			switch ( $row['action'] ) {
				case 'update':
					$updated[] = $base + [
						'old_version' => (string) $row['old_version'],
						'new_version' => (string) $row['new_version'],
						'date'        => $this->site_date( (string) $row['created_at'] ),
						'source'      => (string) $row['source'],
					];
					break;
				case 'install':
					$added[] = $base + [
						'version' => (string) $row['new_version'],
						'date'    => $this->site_date( (string) $row['created_at'] ),
					];
					break;
				case 'delete':
					$removed[] = $base + [
						'version' => '' !== (string) $row['old_version'] ? (string) $row['old_version'] : (string) $row['new_version'],
						'date'    => $this->site_date( (string) $row['created_at'] ),
					];
					break;
			}
		}

		$plugins  = $this->facts->plugins();
		$inactive = [];
		$active   = 0;

		foreach ( $plugins as $plugin ) {
			if ( $plugin['active'] ) {
				++$active;
				continue;
			}

			$inactive[] = [
				'slug'    => $plugin['slug'],
				'name'    => $plugin['name'],
				'version' => $plugin['version'],
			];
		}

		$by_type = static fn ( array $rows, string $type ): int => count( array_filter( $rows, static fn ( array $row ): bool => $type === $row['type'] ) );

		return [
			'counts'   => [
				'installed'        => count( $plugins ),
				'active'           => $active,
				'updated'          => $by_type( $updated, 'plugin' ),
				'added'            => $by_type( $added, 'plugin' ),
				'removed'          => $by_type( $removed, 'plugin' ),
				'inactive'         => count( $inactive ),
				'themes_installed' => $this->facts->themes_installed(),
				'themes_updated'   => $by_type( $updated, 'theme' ),
			],
			'updated'  => $updated,
			'added'    => $added,
			'removed'  => $removed,
			'inactive' => $inactive,
		];
	}

	/**
	 * User management (auto fields).
	 *
	 * @param Period                                    $period  Period.
	 * @param array<string, list<array<string, mixed>>> $changes Changes by type.
	 *
	 * @return array<string, mixed>
	 */
	private function user_management( Period $period, array $changes ): array {
		$counts  = $this->facts->user_counts();
		$added   = [];
		$removed = [];
		$seen    = [];

		foreach ( $changes['user'] as $row ) {
			if ( 'install' === $row['action'] ) {
				$login          = (string) $row['item'];
				$seen[ $login ] = true;
				$added[]        = [
					'login' => $login,
					'role'  => (string) $row['new_version'],
					'date'  => $this->site_date( (string) $row['created_at'] ),
				];
			} elseif ( 'delete' === $row['action'] ) {
				$removed[] = [
					'login' => (string) $row['item'],
					'date'  => $this->site_date( (string) $row['created_at'] ),
				];
			}
		}

		// The change log may predate the listener; user_registered is authoritative for additions.
		foreach ( $this->facts->users_registered( $this->utc( $period->start() ), $this->utc( $period->end()->modify( '+1 second' ) ) ) as $user ) {
			if ( isset( $seen[ $user['login'] ] ) ) {
				continue;
			}

			$added[] = [
				'login' => $user['login'],
				'role'  => $user['role'],
				'date'  => $this->site_date( $user['registered'] ),
			];
		}

		return [
			'total_users'    => $counts['total'],
			'users_per_role' => $counts['roles'],
			'administrators' => $this->facts->administrators(),
			'users_added'    => $added,
			'users_removed'  => $removed,
		];
	}

	/**
	 * Hosting (auto fields).
	 *
	 * @return array<string, mixed>
	 */
	private function hosting(): array {
		$data = $this->latest_data( 'hosting.platform' );

		return [
			'host'               => self::text( self::pick( $data, [ 'host' ] ) ) ?? $this->facts->host(),
			'server_type'        => self::text( self::pick( $data, [ 'server_type', 'os' ] ) ) ?? $this->facts->server_type(),
			'web_server'         => self::text( self::pick( $data, [ 'web_server', 'server_software' ] ) ) ?? $this->facts->web_server(),
			'mysql_version'      => self::text( self::pick( $data, [ 'mysql_version', 'db_version' ] ) ) ?? $this->facts->mysql_version(),
			'php_version'        => self::text( self::pick( $data, [ 'php_version' ] ) ) ?? $this->facts->php_version(),
			'memory_limit'       => self::text( self::pick( $data, [ 'memory_limit' ] ) ) ?? $this->facts->memory_limit(),
			'php_recommendation' => $this->facts->php_recommendation(),
		];
	}

	/**
	 * Domains (auto fields).
	 *
	 * @return array<string, mixed>
	 */
	private function domains(): array {
		$host = wp_parse_url( $this->facts->home_url(), PHP_URL_HOST );

		return [
			'primary_domain' => is_string( $host ) && '' !== $host ? $host : null,
		];
	}

	/**
	 * SSL (auto fields).
	 *
	 * @param DateTimeImmutable $now Now.
	 *
	 * @return array<string, mixed>
	 */
	private function ssl( DateTimeImmutable $now ): array {
		$data    = $this->latest_data( 'ssl.certificate' );
		$expires = self::pick( $data, [ 'expires_at', 'valid_to' ] );
		$date    = null;

		if ( is_int( $expires ) || ( is_string( $expires ) && ctype_digit( $expires ) ) ) {
			$date = ( new DateTimeImmutable( '@' . (int) $expires ) )->setTimezone( $this->tz );
		} elseif ( is_string( $expires ) && '' !== $expires ) {
			try {
				$date = ( new DateTimeImmutable( $expires, new DateTimeZone( 'UTC' ) ) )->setTimezone( $this->tz );
			} catch ( \Throwable ) {
				$date = null;
			}
		}

		$days_left = null !== $date
			? (int) floor( ( $date->getTimestamp() - $now->getTimestamp() ) / DAY_IN_SECONDS )
			: self::integer( self::pick( $data, [ 'days_left' ] ) );

		return [
			'https'      => str_starts_with( $this->facts->home_url(), 'https://' ),
			'issuer'     => self::text( self::pick( $data, [ 'issuer' ] ) ),
			'expires_at' => $date?->format( 'Y-m-d' ),
			'days_left'  => $days_left,
		];
	}

	/**
	 * Caching and CDN (auto fields).
	 *
	 * @return array<string, mixed>
	 */
	private function caching_cdn(): array {
		$cache = $this->latest_data( 'perf.cache' );
		$cdn   = $this->latest_data( 'detect.cdn' );

		return [
			'page_cache'   => self::text( self::pick( $cache, [ 'page_cache' ] ) ),
			'object_cache' => self::text( self::pick( $cache, [ 'object_cache' ] ) ) ?? $this->facts->object_cache(),
			'cdn'          => self::text( self::pick( $cdn, [ 'cdn', 'provider', 'name' ] ) ),
		];
	}

	/**
	 * Security (auto fields).
	 *
	 * @param list<array<string, mixed>> $vulns Vulnerabilities overlapping the period.
	 *
	 * @return array<string, mixed>
	 */
	private function security( array $vulns ): array {
		$checksums_run = $this->runs->latest( 'security.checksums' );
		$checksums     = null;

		if ( null !== $checksums_run ) {
			$data      = (array) $checksums_run['data'];
			$modified  = self::pick( $data, [ 'modified_files', 'modified' ] );
			$checksums = [
				'status'         => self::text( $data['status'] ?? null ) ?? (string) $checksums_run['status'],
				'modified_files' => is_array( $modified ) ? array_values( array_map( 'strval', $modified ) ) : [],
			];
		}

		$config = self::pick( $this->latest_data( 'security.config' ), [ 'checks' ] );
		$checks = null;

		if ( is_array( $config ) ) {
			$checks = [];

			foreach ( $config as $check ) {
				if ( is_array( $check ) ) {
					$checks[] = [
						'id'     => (string) ( $check['id'] ?? '' ),
						'label'  => (string) ( $check['label'] ?? '' ),
						'status' => (string) ( $check['status'] ?? '' ),
					];
				}
			}
		}

		return [
			'vulnerabilities' => $vulns,
			'core_checksums'  => $checksums,
			'config_checks'   => $checks,
		];
	}

	/**
	 * Forms (auto fields).
	 *
	 * @return array<string, mixed>
	 */
	private function forms(): array {
		$forms  = $this->latest_data( 'detect.forms' );
		$mailer = $this->latest_data( 'detect.mailer' );

		$plugins = null;

		if ( is_array( self::pick( $forms, [ 'plugins' ] ) ) ) {
			$plugins = [];

			foreach ( (array) self::pick( $forms, [ 'plugins' ] ) as $plugin ) {
				if ( is_array( $plugin ) ) {
					$plugins[] = [
						'name'       => (string) ( $plugin['name'] ?? '' ),
						'slug'       => (string) ( $plugin['slug'] ?? '' ),
						'form_count' => (int) ( $plugin['form_count'] ?? 0 ),
						'addons'     => array_values( array_map( 'strval', (array) ( $plugin['addons'] ?? [] ) ) ),
					];
				}
			}
		}

		$integrations = null;

		if ( is_array( self::pick( $forms, [ 'integrations' ] ) ) ) {
			$integrations = [];

			foreach ( (array) self::pick( $forms, [ 'integrations' ] ) as $integration ) {
				$name = is_array( $integration ) ? ( $integration['name'] ?? '' ) : $integration;

				if ( is_scalar( $name ) && '' !== (string) $name ) {
					$integrations[] = [ 'name' => (string) $name ];
				}
			}
		}

		return [
			'form_plugins'   => $plugins,
			'sending_method' => self::text( self::pick( $mailer, [ 'method', 'sending_method', 'mailer' ] ) ),
			'integrations'   => $integrations,
		];
	}

	/**
	 * Analytics and tagging (auto fields).
	 *
	 * @return array<string, mixed>
	 */
	private function analytics(): array {
		$run = $this->runs->latest( 'detect.analytics' );

		if ( null === $run ) {
			return [];
		}

		$data = (array) $run['data'];

		return [
			'gtm_ids' => self::strings( $data['gtm_ids'] ?? [] ),
			'ga_ids'  => array_values( array_unique( array_merge( self::strings( $data['ga_ids'] ?? [] ), self::strings( $data['ga4_ids'] ?? [] ), self::strings( $data['ua_ids'] ?? [] ) ) ) ),
		];
	}

	/**
	 * Accessibility (auto fields).
	 *
	 * @return array<string, mixed>
	 */
	private function accessibility(): array {
		$data = $this->latest_data( 'detect.accessibility' );
		$url  = self::text( self::pick( $data, [ 'statement_page', 'statement_url' ] ) );

		return [
			'third_party_tool' => self::text( self::pick( $data, [ 'third_party_tool', 'tool' ] ) ),
			'statement_page'   => null !== $url ? esc_url_raw( $url ) : null,
		];
	}

	/**
	 * PHP errors (auto fields): counts and the top 10 signatures in the period.
	 *
	 * @param array{counts: array<string, int>, top: list<array<string, mixed>>} $events Period events.
	 *
	 * @return array<string, mixed>
	 */
	private function php_errors( array $events ): array {
		$counts = $events['counts'];

		return [
			'fatal_count'       => $counts['fatal'],
			'warning_count'     => $counts['warning'] + $counts['notice'],
			'deprecation_count' => $counts['deprecation'] + $counts['doing_it_wrong'],
			'top_signatures'    => $events['top'],
		];
	}

	/**
	 * Smoke checks (auto fields): per URL runs, pass rate and average response.
	 *
	 * @param Period $period Period.
	 *
	 * @return array<string, mixed>
	 */
	private function smoke_checks( Period $period ): array {
		$stats = [];

		foreach ( $this->runs_in_period( 'smoke.urls', $period ) as $run ) {
			$urls = ( (array) $run['data'] )['urls'] ?? [];

			if ( ! is_array( $urls ) ) {
				continue;
			}

			foreach ( $urls as $result ) {
				if ( ! is_array( $result ) || empty( $result['url'] ) ) {
					continue;
				}

				$ok = self::smoke_ok( $result );

				if ( null === $ok ) {
					continue; // Loopback blocked: not a site failure, not a run.
				}

				$url = (string) $result['url'];

				$stats[ $url ] ??= [
					'runs' => 0,
					'ok'   => 0,
					'ms'   => 0,
					'ms_n' => 0,
				];

				++$stats[ $url ]['runs'];
				$stats[ $url ]['ok'] += $ok ? 1 : 0;

				$ms = self::pick( $result, [ 'response_ms', 'ms', 'time_ms' ] );

				if ( is_numeric( $ms ) ) {
					$stats[ $url ]['ms'] += (float) $ms;
					++$stats[ $url ]['ms_n'];
				}
			}
		}

		if ( [] === $stats ) {
			return [ 'urls' => null ];
		}

		$urls = [];

		foreach ( $stats as $url => $stat ) {
			$urls[] = [
				'url'             => (string) $url,
				'runs'            => $stat['runs'],
				'pass_rate'       => round( 100 * $stat['ok'] / $stat['runs'], 1 ),
				'avg_response_ms' => $stat['ms_n'] > 0 ? (int) round( $stat['ms'] / $stat['ms_n'] ) : null,
			];
		}

		return [ 'urls' => $urls ];
	}

	/**
	 * Performance (auto fields): response trend and sizes.
	 *
	 * @param Period $period Period.
	 *
	 * @return array<string, mixed>
	 */
	private function performance( Period $period ): array {
		$database = $this->latest_data( 'perf.database' );
		$autoload = self::integer( self::pick( $database, [ 'autoload_size_bytes' ] ) ) ?? $this->facts->autoload_size_bytes();

		return [
			'response_trend'      => $this->response_trend( $period ),
			'database_size_bytes' => self::integer( self::pick( $database, [ 'database_size_bytes', 'total_size_bytes' ] ) ),
			'autoload_size_bytes' => $autoload,
		];
	}

	/**
	 * Average server response time per bucket (weekly or monthly).
	 *
	 * @param Period $period Period.
	 *
	 * @return list<array{label: string, start: string, avg_ms: int|null}>|null Null when no run exists in the period.
	 */
	private function response_trend( Period $period ): ?array {
		$runs = $this->runs_in_period( 'perf.response', $period );

		if ( [] === $runs ) {
			return null;
		}

		$trend = [];

		foreach ( $this->buckets( $period ) as $bucket ) {
			$sum = 0.0;
			$n   = 0;

			foreach ( $runs as $run ) {
				$at = (string) $run['created_at'];

				if ( $at < $this->utc( $bucket['start'] ) || $at >= $this->utc( $bucket['end'] ) ) {
					continue;
				}

				$avg = self::run_avg_ms( (array) $run['data'] );

				if ( null !== $avg ) {
					$sum += $avg;
					++$n;
				}
			}

			$trend[] = [
				'label'  => $bucket['label'],
				'start'  => $bucket['start']->format( 'Y-m-d' ),
				'avg_ms' => $n > 0 ? (int) round( $sum / $n ) : null,
			];
		}

		return $trend;
	}

	// Period totals (quarterly, annual, long custom).

	/**
	 * Whether the period gets totals and a monthly breakdown.
	 *
	 * @param Period $period Period.
	 */
	private function wants_totals( Period $period ): bool {
		return $this->monthly_points( $period );
	}

	/**
	 * Whether trend points are monthly rather than weekly.
	 *
	 * @param Period $period Period.
	 */
	private function monthly_points( Period $period ): bool {
		if ( in_array( $period->type(), [ Period::TYPE_QUARTER, Period::TYPE_YEAR ], true ) ) {
			return true;
		}

		return Period::TYPE_CUSTOM === $period->type()
			&& ( $period->end()->getTimestamp() - $period->start()->getTimestamp() ) > self::WEEKLY_MAX_DAYS * DAY_IN_SECONDS;
	}

	/**
	 * Totals for the whole period.
	 *
	 * @param array<string, list<array<string, mixed>>>                          $changes Changes by type.
	 * @param array{counts: array<string, int>, top: list<array<string, mixed>>} $events  Events.
	 * @param list<array<string, mixed>>                                         $vulns   Vulnerabilities.
	 * @param Period                                                             $period  Period.
	 *
	 * @return array<string, mixed>
	 */
	private function totals( array $changes, array $events, array $vulns, Period $period ): array {
		$count = static fn ( array $rows, string $action ): int => count( array_filter( $rows, static fn ( array $row ): bool => $action === $row['action'] ) );

		$start = $period->start()->format( 'Y-m-d' );

		return [
			'plugins_updated'          => $count( $changes['plugin'], 'update' ),
			'plugins_added'            => $count( $changes['plugin'], 'install' ),
			'plugins_removed'          => $count( $changes['plugin'], 'delete' ),
			'themes_updated'           => $count( $changes['theme'], 'update' ),
			'core_updates'             => $count( $changes['core'], 'update' ),
			'users_added'              => $count( $changes['user'], 'install' ),
			'users_removed'            => $count( $changes['user'], 'delete' ),
			'fatal'                    => $events['counts']['fatal'],
			'warning'                  => $events['counts']['warning'] + $events['counts']['notice'],
			'deprecation'              => $events['counts']['deprecation'] + $events['counts']['doing_it_wrong'],
			'vulnerabilities_found'    => count( array_filter( $vulns, static fn ( array $row ): bool => (string) $row['first_seen'] >= $start ) ),
			'vulnerabilities_resolved' => count( array_filter( $vulns, static fn ( array $row ): bool => null !== $row['resolved_at'] ) ),
		];
	}

	/**
	 * One entry per calendar month of the period.
	 *
	 * @param Period                                    $period  Period.
	 * @param array<string, list<array<string, mixed>>> $changes Changes by type.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function monthly( Period $period, array $changes ): array {
		$monthly   = [];
		$responses = $this->runs_in_period( 'perf.response', $period );
		$databases = $this->runs_in_period( 'perf.database', $period );

		foreach ( $this->buckets( $period, true ) as $bucket ) {
			$since = $this->utc( $bucket['start'] );
			$until = $this->utc( $bucket['end'] );
			$in    = static fn ( array $row ): bool => (string) $row['created_at'] >= $since && (string) $row['created_at'] < $until;
			$count = static fn ( array $rows, string $action ): int => count( array_filter( $rows, static fn ( array $row ): bool => $in( $row ) && $action === $row['action'] ) );
			$types = $this->events->counts_by_type( $since, $until );
			$perf  = $this->month_performance( $responses, $databases, $since, $until );

			$monthly[] = [
				'label'           => $bucket['label'],
				'start'           => $bucket['start']->format( 'Y-m-d' ),
				'plugins_updated' => $count( $changes['plugin'], 'update' ),
				'plugins_added'   => $count( $changes['plugin'], 'install' ),
				'plugins_removed' => $count( $changes['plugin'], 'delete' ),
				'fatal'           => $types['fatal'],
				'warning'         => $types['warning'] + $types['notice'],
				'deprecation'     => $types['deprecation'] + $types['doing_it_wrong'],
			] + $perf;
		}

		return $monthly;
	}

	/**
	 * Performance figures of one month, from runs already loaded.
	 *
	 * @param list<array<string, mixed>> $responses perf.response runs of the period.
	 * @param list<array<string, mixed>> $databases perf.database runs of the period.
	 * @param string                     $since     Month start, UTC 'Y-m-d H:i:s' (inclusive).
	 * @param string                     $until     Month end, UTC 'Y-m-d H:i:s' (exclusive).
	 *
	 * @return array{avg_response_ms: int|null, database_size_bytes: int|null, autoload_size_bytes: int|null}
	 */
	private function month_performance( array $responses, array $databases, string $since, string $until ): array {
		$in_month = static fn ( array $run ): bool => (string) $run['created_at'] >= $since && (string) $run['created_at'] < $until;

		$sum = 0.0;
		$n   = 0;

		foreach ( array_filter( $responses, $in_month ) as $run ) {
			$avg = self::run_avg_ms( (array) $run['data'] );

			if ( null !== $avg ) {
				$sum += $avg;
				++$n;
			}
		}

		$latest = [];

		foreach ( array_filter( $databases, $in_month ) as $run ) {
			if ( [] === $latest || (string) $run['created_at'] >= (string) $latest['created_at'] ) {
				$latest = $run;
			}
		}

		$data = [] === $latest ? [] : (array) $latest['data'];

		return [
			'avg_response_ms'     => $n > 0 ? (int) round( $sum / $n ) : null,
			'database_size_bytes' => self::integer( self::pick( $data, [ 'database_size_bytes', 'total_size_bytes' ] ) ),
			'autoload_size_bytes' => self::integer( self::pick( $data, [ 'autoload_size_bytes' ] ) ),
		];
	}

	/**
	 * Trend buckets covering the period.
	 *
	 * @param Period $period       Period.
	 * @param bool   $force_months Always use calendar months.
	 *
	 * @return list<array{label: string, start: DateTimeImmutable, end: DateTimeImmutable}> `end` is exclusive.
	 */
	private function buckets( Period $period, bool $force_months = false ): array {
		$buckets = [];
		$cursor  = $period->start()->setTimezone( $this->tz );
		$limit   = $period->end()->setTimezone( $this->tz )->modify( '+1 second' );
		$monthly = $force_months || $this->monthly_points( $period );

		$guard = 0;

		while ( $cursor < $limit && $guard++ < 400 ) {
			$next = $monthly
				? $cursor->modify( 'first day of next month' )->setTime( 0, 0, 0 )
				: $cursor->modify( '+7 days' );

			if ( $next > $limit ) {
				$next = $limit;
			}

			$buckets[] = [
				'label' => $monthly
					? $cursor->format( 'M Y' )
					/* translators: %s: first day of the week, e.g. 5 Oct. */
					: sprintf( __( 'Week of %s', 'rtcamp-amc-copilot' ), $cursor->format( 'j M' ) ),
				'start' => $cursor,
				'end'   => $next,
			];

			$cursor = $next;
		}

		return $buckets;
	}

	// Data access.

	/**
	 * Changes in the period, oldest first, grouped by type.
	 *
	 * @param Period $period Period.
	 *
	 * @return array<string, list<array<string, mixed>>> Keys: plugin, theme, core, user.
	 */
	private function period_changes( Period $period ): array {
		$grouped = [
			'plugin' => [],
			'theme'  => [],
			'core'   => [],
			'user'   => [],
		];

		foreach ( array_keys( $grouped ) as $type ) {
			for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
				$result = $this->changes->query(
					[
						'type'     => $type,
						'since'    => $this->utc( $period->start() ),
						'until'    => $this->utc( $period->end()->modify( '+1 second' ) ),
						'page'     => $page,
						'per_page' => 500,
					]
				);

				foreach ( $result['items'] as $row ) {
					$grouped[ $type ][] = $row;
				}

				if ( count( $result['items'] ) < 500 ) {
					break;
				}
			}

			usort(
				$grouped[ $type ],
				static fn ( array $a, array $b ): int => [ $a['created_at'], $a['id'] ] <=> [ $b['created_at'], $b['id'] ]
			);
		}

		return $grouped;
	}

	/**
	 * Every core update ever logged, oldest first.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function all_core_updates(): array {
		$rows = [];

		for ( $page = 1; $page <= 4; $page++ ) {
			$result = $this->changes->query(
				[
					'type'     => 'core',
					'action'   => 'update',
					'page'     => $page,
					'per_page' => 500,
				]
			);

			foreach ( $result['items'] as $row ) {
				$rows[] = $row;
			}

			if ( count( $result['items'] ) < 500 ) {
				break;
			}
		}

		usort( $rows, static fn ( array $a, array $b ): int => [ $a['created_at'], $a['id'] ] <=> [ $b['created_at'], $b['id'] ] );

		return $rows;
	}

	/**
	 * The WordPress version in force at a UTC moment.
	 *
	 * The new version of the last update at or before the moment; else the old
	 * version of the first update after it; else the version now.
	 *
	 * @param list<array<string, mixed>> $updates Every core update, oldest first.
	 * @param string                     $at      UTC 'Y-m-d H:i:s'.
	 */
	private function core_version_at( array $updates, string $at ): string {
		$version = null;

		foreach ( $updates as $row ) {
			if ( (string) $row['created_at'] < $at ) {
				$version = (string) $row['new_version'];
			}
		}

		if ( null !== $version && '' !== $version ) {
			return $version;
		}

		foreach ( $updates as $row ) {
			if ( (string) $row['created_at'] >= $at && '' !== (string) $row['old_version'] ) {
				return (string) $row['old_version'];
			}
		}

		return $this->facts->wp_version();
	}

	/**
	 * Captured event counts and top signatures for the period.
	 *
	 * @param Period $period Period.
	 *
	 * @return array{counts: array<string, int>, top: list<array<string, mixed>>}
	 */
	private function period_events( Period $period ): array {
		$since = $this->utc( $period->start() );
		$until = $this->utc( $period->end()->modify( '+1 second' ) );
		$top   = [];

		foreach ( $this->events->grouped(
			[
				'since' => $since,
				'until' => $until,
				'limit' => 10,
			]
		) as $row ) {
			$top[] = [
				'type'    => (string) $row['type'],
				'message' => (string) $row['message'],
				'source'  => (string) $row['source'],
				'count'   => (int) $row['count'],
			];
		}

		return [
			'counts' => $this->events->counts_by_type( $since, $until ),
			'top'    => $top,
		];
	}

	/**
	 * Vulnerability findings that were open at any time in the period.
	 *
	 * @param Period $period Period.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function vulnerabilities( Period $period ): array {
		$start = $this->utc( $period->start() );
		$end   = $this->utc( $period->end()->modify( '+1 second' ) );
		$list  = [];

		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
			$result = $this->findings->query(
				[
					'check_id' => 'vuln.match',
					'page'     => $page,
					'per_page' => 500,
				]
			);

			foreach ( $result['items'] as $row ) {
				$resolved = is_string( $row['resolved_at'] ) && ! str_starts_with( $row['resolved_at'], '0000' ) ? $row['resolved_at'] : null;

				if ( (string) $row['first_seen'] >= $end || ( null !== $resolved && $resolved < $start ) ) {
					continue;
				}

				$data   = (array) $row['data'];
				$list[] = [
					'component'   => (string) ( $data['name'] ?? $data['slug'] ?? $row['title'] ),
					'reference'   => (string) ( $data['reference'] ?? $row['dedupe_key'] ),
					'severity'    => (string) $row['severity'],
					'status'      => null !== $resolved ? 'resolved' : (string) $row['status'],
					'fixed_in'    => isset( $data['fixed_in'] ) && is_scalar( $data['fixed_in'] ) ? (string) $data['fixed_in'] : null,
					'first_seen'  => $this->site_date( (string) $row['first_seen'] ),
					'resolved_at' => null !== $resolved ? $this->site_date( $resolved ) : null,
				];
			}

			if ( count( $result['items'] ) < 500 ) {
				break;
			}
		}

		return $list;
	}

	/**
	 * Finished runs of a check in the period.
	 *
	 * @param string $check_id Check id.
	 * @param Period $period   Period.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function runs_in_period( string $check_id, Period $period ): array {
		$rows = [];

		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
			$result = $this->runs->query(
				[
					'check_id' => $check_id,
					'since'    => $this->utc( $period->start() ),
					'until'    => $this->utc( $period->end()->modify( '+1 second' ) ),
					'page'     => $page,
					'per_page' => 500,
				]
			);

			foreach ( $result['items'] as $row ) {
				if ( in_array( $row['status'], RunsRepository::RESULT_STATUSES, true ) ) {
					$rows[] = $row;
				}
			}

			if ( count( $result['items'] ) < 500 ) {
				break;
			}
		}

		return $rows;
	}

	/**
	 * The data of a check's latest finished run ([] when it never ran).
	 *
	 * @param string $check_id Check id.
	 *
	 * @return array<string, mixed>
	 */
	private function latest_data( string $check_id ): array {
		$run = $this->runs->latest( $check_id );

		return null === $run ? [] : (array) $run['data'];
	}

	// Helpers.

	/**
	 * Now, in UTC.
	 */
	private function now(): DateTimeImmutable {
		if ( is_callable( $this->now ) ) {
			$value = ( $this->now )();

			if ( $value instanceof DateTimeImmutable ) {
				return $value;
			}
		}

		return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * A moment as a UTC 'Y-m-d H:i:s' string.
	 *
	 * @param DateTimeImmutable $moment Moment.
	 */
	private function utc( DateTimeImmutable $moment ): string {
		return $moment->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * A stored UTC datetime as a site-time date.
	 *
	 * @param string $utc UTC 'Y-m-d H:i:s'.
	 */
	private function site_date( string $utc ): string {
		return ( new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) ) )->setTimezone( $this->tz )->format( 'Y-m-d' );
	}

	/**
	 * Display name of a changed item.
	 *
	 * @param array<string, mixed> $row Change row.
	 */
	private function change_name( array $row ): string {
		return '' !== (string) $row['item_name'] ? (string) $row['item_name'] : (string) $row['item'];
	}

	/**
	 * First present value among several key names.
	 *
	 * @param array<mixed, mixed> $data Data.
	 * @param list<string>        $keys Candidate keys.
	 */
	private static function pick( array $data, array $keys ): mixed {
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $data ) && null !== $data[ $key ] ) {
				return $data[ $key ];
			}
		}

		return null;
	}

	/**
	 * A value as display text, or null.
	 *
	 * @param mixed $value Value.
	 */
	private static function text( mixed $value ): ?string {
		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'rtcamp-amc-copilot' ) : __( 'No', 'rtcamp-amc-copilot' );
		}

		if ( is_array( $value ) ) {
			$parts = self::strings( $value );

			return [] === $parts ? null : implode( ', ', $parts );
		}

		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$text = trim( (string) $value );

		return '' === $text ? null : $text;
	}

	/**
	 * A value as an int, or null.
	 *
	 * @param mixed $value Value.
	 */
	private static function integer( mixed $value ): ?int {
		return is_numeric( $value ) ? (int) $value : null;
	}

	/**
	 * A list of scalar values as strings.
	 *
	 * @param mixed $value Value.
	 *
	 * @return list<string>
	 */
	private static function strings( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$strings = [];

		foreach ( $value as $item ) {
			if ( is_scalar( $item ) && '' !== trim( (string) $item ) ) {
				$strings[] = trim( (string) $item );
			}
		}

		return $strings;
	}

	/**
	 * Whether one smoke result passed (null = loopback blocked, not counted).
	 *
	 * @param array<mixed, mixed> $result One URL result.
	 */
	private static function smoke_ok( array $result ): ?bool {
		$status = $result['status'] ?? $result['last_status'] ?? null;

		if ( 'loopback_blocked' === $status ) {
			return null;
		}

		if ( isset( $result['ok'] ) ) {
			return (bool) $result['ok'];
		}

		return 'pass' === $status;
	}

	/**
	 * Average response time of one `perf.response` run.
	 *
	 * @param array<mixed, mixed> $data Run data.
	 */
	private static function run_avg_ms( array $data ): ?float {
		if ( isset( $data['avg_ms'] ) && is_numeric( $data['avg_ms'] ) ) {
			return (float) $data['avg_ms'];
		}

		$sum = 0.0;
		$n   = 0;

		foreach ( (array) ( $data['urls'] ?? [] ) as $result ) {
			$ms = is_array( $result ) ? self::pick( $result, [ 'response_ms', 'ms', 'avg_ms' ] ) : null;

			if ( is_numeric( $ms ) ) {
				$sum += (float) $ms;
				++$n;
			}
		}

		return $n > 0 ? $sum / $n : null;
	}
}
