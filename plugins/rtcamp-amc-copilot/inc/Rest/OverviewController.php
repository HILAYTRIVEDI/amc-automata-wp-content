<?php
/**
 * Route: /overview.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Rest;

use AMC\Checks\Group;
use AMC\Checks\Registry;
use AMC\Core\KillSwitch;
use AMC\Runner\Scheduler;
use AMC\Storage\FindingsRepository;
use AMC\Storage\RunsRepository;
use WP_REST_Response;

/**
 * Class OverviewController
 *
 * Owner: Wave 0 (implemented). Also used by the Dashboard widget (lane C).
 *
 * GET /amc/v1/overview → Overview (see get_overview()).
 *
 * @since 0.1.0
 */
final class OverviewController extends AbstractController {

	/**
	 * Status order, worst last.
	 */
	private const STATUS_RANK = [
		'info'  => 0,
		'pass'  => 1,
		'warn'  => 2,
		'error' => 3,
		'fail'  => 4,
	];

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		$this->route( '/overview', self::readable(), [ $this, 'get_overview' ], [ $this, 'manage' ] );
	}

	/**
	 * GET /overview.
	 *
	 * {
	 *   status: 'pass'|'warn'|'fail'|'info',   worst latest result across checks
	 *   kill_switch: bool,
	 *   findings_by_severity: { critical, high, medium, low, info },   open only
	 *   groups: list<{ group, label, status: string|null, last_run_at: string|null, checks: int }>,
	 *   versions: { WordPress, php, mysql, theme: { name, version, parent: {name, version}|null } },
	 *   counts: { plugins, plugins_active, themes, pending_updates: { core, plugins, themes, total } },
	 *   next_runs: list<{ check_id, label, next_run_at }>   soonest first, max 10
	 * }
	 */
	public function get_overview(): WP_REST_Response {
		$registry = new Registry();
		$runs     = new RunsRepository();
		$latest   = $runs->latest_per_check();

		return new WP_REST_Response(
			[
				'status'               => $this->overall_status( $latest ),
				'kill_switch'          => KillSwitch::is_active(),
				'findings_by_severity' => ( new FindingsRepository() )->open_counts_by_severity(),
				'groups'               => $this->groups( $registry, $latest ),
				'versions'             => $this->versions(),
				'counts'               => $this->counts(),
				'next_runs'            => $this->next_runs( $registry, $runs ),
			]
		);
	}

	/**
	 * Worst status of the latest runs ('info' when nothing has run).
	 *
	 * @param array<string, array<string, mixed>> $latest Latest run per check.
	 */
	private function overall_status( array $latest ): string {
		$worst = 'info';

		foreach ( $latest as $row ) {
			$status = (string) $row['status'];
			if ( ( self::STATUS_RANK[ $status ] ?? 0 ) > self::STATUS_RANK[ $worst ] ) {
				$worst = $status;
			}
		}

		return 'error' === $worst ? 'warn' : $worst;
	}

	/**
	 * Per-group summary.
	 *
	 * @param Registry                            $registry Registry.
	 * @param array<string, array<string, mixed>> $latest   Latest run per check.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function groups( Registry $registry, array $latest ): array {
		$groups = [];

		foreach ( Group::cases() as $group ) {
			$checks   = $registry->in_group( $group );
			$status   = null;
			$last_run = null;

			foreach ( array_keys( $checks ) as $id ) {
				if ( ! isset( $latest[ $id ] ) ) {
					continue;
				}
				$row = $latest[ $id ];
				if ( null === $status || self::STATUS_RANK[ $row['status'] ] > self::STATUS_RANK[ $status ] ) {
					$status = (string) $row['status'];
				}
				if ( null === $last_run || $row['created_at'] > $last_run ) {
					$last_run = (string) $row['created_at'];
				}
			}

			$groups[] = [
				'group'       => $group->value,
				'label'       => $group->label(),
				'status'      => $status,
				'last_run_at' => self::iso( $last_run ),
				'checks'      => count( $checks ),
			];
		}

		return $groups;
	}

	/**
	 * Software versions.
	 *
	 * @return array<string, mixed>
	 */
	private function versions(): array {
		global $wpdb;

		$theme  = wp_get_theme();
		$parent = $theme->parent();

		return [
			'wordpress' => wp_get_wp_version(),
			'php'       => PHP_VERSION,
			'mysql'     => (string) $wpdb->db_version(),
			'theme'     => [
				'name'    => (string) $theme->get( 'Name' ),
				'version' => (string) $theme->get( 'Version' ),
				'parent'  => $parent instanceof \WP_Theme
					? [
						'name'    => (string) $parent->get( 'Name' ),
						'version' => (string) $parent->get( 'Version' ),
					]
					: null,
			],
		];
	}

	/**
	 * Plugin/theme counts and pending updates (read from core transients; no remote calls).
	 *
	 * @return array<string, mixed>
	 */
	private function counts(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins      = get_plugins();
		$active       = array_filter( array_keys( $plugins ), 'is_plugin_active' );
		$plugin_upd   = get_site_transient( 'update_plugins' );
		$theme_upd    = get_site_transient( 'update_themes' );
		$core_upd     = get_site_transient( 'update_core' );
		$core_pending = 0;

		if ( is_object( $core_upd ) && isset( $core_upd->updates ) && is_array( $core_upd->updates ) ) {
			foreach ( $core_upd->updates as $offer ) {
				if ( is_object( $offer ) && 'upgrade' === ( $offer->response ?? '' ) ) {
					$core_pending = 1;
					break;
				}
			}
		}

		$plugins_pending = is_object( $plugin_upd ) && isset( $plugin_upd->response ) ? count( (array) $plugin_upd->response ) : 0;
		$themes_pending  = is_object( $theme_upd ) && isset( $theme_upd->response ) ? count( (array) $theme_upd->response ) : 0;

		return [
			'plugins'         => count( $plugins ),
			'plugins_active'  => count( $active ),
			'themes'          => count( wp_get_themes() ),
			'pending_updates' => [
				'core'    => $core_pending,
				'plugins' => $plugins_pending,
				'themes'  => $themes_pending,
				'total'   => $core_pending + $plugins_pending + $themes_pending,
			],
		];
	}

	/**
	 * Next scheduled runs, soonest first.
	 *
	 * @param Registry       $registry Registry.
	 * @param RunsRepository $runs     Runs.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function next_runs( Registry $registry, RunsRepository $runs ): array {
		$checks = $registry->all();
		$next   = array_filter( Scheduler::next_runs( $checks, $runs->last_run_times() ), static fn ( $time ): bool => null !== $time );

		asort( $next );

		$items = [];
		foreach ( array_slice( $next, 0, 10, true ) as $id => $time ) {
			$items[] = [
				'check_id'    => $id,
				'label'       => $checks[ $id ]->label(),
				'next_run_at' => gmdate( 'Y-m-d\TH:i:s\Z', (int) $time ),
			];
		}

		return $items;
	}
}
