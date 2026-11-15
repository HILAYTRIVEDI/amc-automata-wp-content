<?php
/**
 * The `amc_report` post type.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports;

use AMC\Core\Brand;
use AMC\Core\Capabilities;
use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractPostType;

/**
 * Class ReportPostType
 *
 * Owner: lane D1.
 *
 * - Private: not public, not queryable, not searchable, no rewrite rules, so
 *   it is never on the front end and never in a sitemap. Shown in wp-admin
 *   and in REST (block editor) for the people who may read it.
 * - Custom capability type `amc_report` with map_meta_cap, and `read` mapped to
 *   its own `read_amc_reports` primitive (so the core `read` capability every
 *   logged-in user has does NOT open published reports):
 *     - amc_manage (engineers): every primitive, so create, edit, publish and
 *       read drafts.
 *     - amc_view_reports (client administrators): `read_amc_reports` only.
 *       Core maps reading a PUBLISHED report to `read_amc_reports`; reading a
 *       draft maps to `edit_post`, which they lack. They cannot open the
 *       editor, drafts, or the list table.
 *     - Anyone else: nothing.
 * - REST `/wp/v2/amc-reports` uses ReportRestController, because core would
 *   otherwise serve published posts of any `show_in_rest` type to anonymous
 *   requests.
 * - Post meta (all single, registered for REST, editable with amc_manage):
 *   _amc_period_type, _amc_period_start, _amc_period_end, _amc_environment,
 *   _amc_snapshot (JSON string, see SnapshotSchema), _amc_snapshot_taken_at,
 *   _amc_report_id (e.g. AMC-NWT-2026-10), and _amc_build_status (D1:
 *   `pending` while the snapshot is being built, `ready`, or `failed`).
 * - Template: every section block in report order (SnapshotSchema), each locked
 *   (move + remove). template_lock is false so custom sections can be inserted
 *   anywhere.
 *
 * @since 0.1.0
 */
final class ReportPostType extends AbstractPostType {

	/**
	 * Post type slug.
	 */
	public const SLUG = 'amc_report';

	/**
	 * Meta keys => type.
	 */
	public const META = [
		'_amc_period_type'       => 'string',
		'_amc_period_start'      => 'string',
		'_amc_period_end'        => 'string',
		'_amc_environment'       => 'string',
		'_amc_snapshot'          => 'string',
		'_amc_snapshot_taken_at' => 'string',
		'_amc_report_id'         => 'string',
		'_amc_build_status'      => 'string',
		'_amc_has_failures'      => 'boolean',
		'_amc_issue_count'       => 'integer',
		'_amc_issues'            => 'string',
		'_amc_send_publish_email'=> 'boolean',
	];

	/**
	 * Primitive capability for reading a published report (the post type's
	 * `read` capability).
	 */
	public const READ_CAP = 'read_amc_reports';

	/**
	 * Build status: the snapshot is queued or being built.
	 */
	public const BUILD_PENDING = 'pending';

	/**
	 * Build status: the snapshot is stored.
	 */
	public const BUILD_READY = 'ready';

	/**
	 * Build status: building the snapshot failed.
	 */
	public const BUILD_FAILED = 'failed';

	/**
	 * {@inheritDoc}
	 */
	public static function get_slug(): string {
		return self::SLUG;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_singular_label(): string {
		return __( 'Maintenance Report', 'rtcamp-amc-copilot' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_plural_label(): string {
		return __( 'Maintenance Reports', 'rtcamp-amc-copilot' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_menu_icon(): string {
		return 'dashicons-media-document';
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		parent::register_hooks();

		add_filter( 'user_has_cap', [ $this, 'map_caps' ], 10, 1 );
		add_filter( 'rest_post_dispatch', [ self::class, 'noindex_headers' ], 10, 3 );
		add_action( 'enqueue_block_assets', [ self::class, 'enqueue_editor_fonts' ] );
		add_action( 'enqueue_block_editor_assets', [ self::class, 'enqueue_editor_assets' ] );
		add_filter( 'manage_' . self::SLUG . '_posts_columns', [ self::class, 'manage_columns' ] );
		add_action( 'manage_' . self::SLUG . '_posts_custom_column', [ self::class, 'render_column' ], 10, 2 );
	}

	/**
	 * Brand typefaces inside the report editor's canvas (the section blocks'
	 * editor styles use them; `enqueue_block_assets` reaches the iframe).
	 */
	public static function enqueue_editor_fonts(): void {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( null === $screen || self::SLUG !== $screen->post_type || ! $screen->is_block_editor() ) {
			return;
		}

		Brand::enqueue_fonts();
	}

	/**
	 * Enqueue the report editor script and styles when editing an amc_report.
	 */
	public static function enqueue_editor_assets(): void {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( null === $screen || self::SLUG !== $screen->post_type || ! $screen->is_block_editor() ) {
			return;
		}

		$manifest_path = AMC_COPILOT_PATH . 'assets/build/js/editor.asset.php';
		$script_path   = AMC_COPILOT_PATH . 'assets/build/js/editor.js';

		if ( file_exists( $script_path ) ) {
			$meta = file_exists( $manifest_path )
				? require $manifest_path
				: [
					'dependencies' => [],
					'version'      => AMC_COPILOT_VERSION,
				];

			wp_enqueue_script(
				'rtcamp-amc-copilot-editor',
				AMC_COPILOT_URL . 'assets/build/js/editor.js',
				$meta['dependencies'] ?? [],
				$meta['version'] ?? AMC_COPILOT_VERSION,
				true
			);

			if ( function_exists( 'wp_set_script_translations' ) ) {
				wp_set_script_translations( 'rtcamp-amc-copilot-editor', 'rtcamp-amc-copilot' );
			}

			$post_id   = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$sent_at   = $post_id > 0 ? (int) get_post_meta( $post_id, ReportEmails::SENT_META, true ) : 0;
			$send_meta = $post_id > 0 ? get_post_meta( $post_id, '_amc_send_publish_email', true ) : '';

			wp_localize_script(
				'rtcamp-amc-copilot-editor',
				'amcReportEditor',
				[
					'recipients'       => ReportEmails::published_recipients(),
					'hasSentEmail'     => $sent_at > 0,
					'sentAt'           => $sent_at,
					'sendPublishEmail' => '' === $send_meta ? true : (bool) $send_meta,
					'restUrl'          => esc_url_raw( rest_url( 'amc/v1' ) ),
					'nonce'            => wp_create_nonce( 'wp_rest' ),
				]
			);
		}

		$css_path = AMC_COPILOT_PATH . 'assets/build/css/editor.css';
		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'rtcamp-amc-copilot-editor',
				AMC_COPILOT_URL . 'assets/build/css/editor.css',
				[],
				(string) filemtime( $css_path )
			);
		}
	}

	/**
	 * Customize post list columns for amc_report.
	 *
	 * @param array<string, string> $columns Existing columns.
	 *
	 * @return array<string, string>
	 */
	public static function manage_columns( array $columns ): array {
		$ordered = [];

		if ( isset( $columns['cb'] ) ) {
			$ordered['cb'] = $columns['cb'];
		}

		$ordered['title']         = $columns['title'] ?? __( 'Title', 'rtcamp-amc-copilot' );
		$ordered['amc_report_id'] = __( 'Report ID', 'rtcamp-amc-copilot' );
		$ordered['amc_period']    = __( 'Period', 'rtcamp-amc-copilot' );
		$ordered['amc_health']    = __( 'Health / Issues', 'rtcamp-amc-copilot' );

		if ( isset( $columns['date'] ) ) {
			$ordered['date'] = $columns['date'];
		}

		return $ordered;
	}

	/**
	 * Render custom column content.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public static function render_column( string $column, int $post_id ): void {
		if ( 'amc_report_id' === $column ) {
			$id = (string) get_post_meta( $post_id, '_amc_report_id', true );
			echo esc_html( '' !== $id ? $id : '—' );
			return;
		}

		if ( 'amc_period' === $column ) {
			$start = (string) get_post_meta( $post_id, '_amc_period_start', true );
			$end   = (string) get_post_meta( $post_id, '_amc_period_end', true );
			if ( '' !== $start && '' !== $end ) {
				echo esc_html( sprintf( '%s – %s', $start, $end ) );
			} else {
				echo esc_html__( '—', 'rtcamp-amc-copilot' );
			}
			return;
		}

		if ( 'amc_health' === $column ) {
			$build_status = (string) get_post_meta( $post_id, '_amc_build_status', true );
			if ( self::BUILD_PENDING === $build_status ) {
				echo '<span class="amc-status-badge amc-status-badge--pending" style="background:#fef3c7;color:#92400e;padding:3px 10px;border-radius:9999px;font-weight:600;font-size:11px;display:inline-flex;align-items:center;gap:4px;">' . esc_html__( 'Generating...', 'rtcamp-amc-copilot' ) . '</span>';
				return;
			}

			// Ensure issues are computed if not yet cached.
			$has_failures_raw = get_post_meta( $post_id, '_amc_has_failures', true );
			if ( '' === $has_failures_raw ) {
				$snapshot = SnapshotSchema::for_post( $post_id );
				ReportIssues::update_post_issues( $post_id, $snapshot );
				$has_failures_raw = get_post_meta( $post_id, '_amc_has_failures', true );
			}

			$has_failures = ! empty( $has_failures_raw );
			$issues       = ReportIssues::for_post( $post_id );
			$count        = count( $issues );

			if ( $has_failures || $count > 0 ) {
				$failed_links = [];

				foreach ( $issues as $issue ) {
					$title = $issue['section_title'] ?? $issue['section'];
					$url   = ! empty( $issue['url'] ) && ! str_contains( $issue['url'], 'screen=overview' )
						? $issue['url']
						: ReportIssues::get_url_for_section( (string) ( $issue['section'] ?? '' ) );

					$failed_links[ $title ] = $url;
				}

				$badge_text = sprintf(
					/* translators: %d: Number of detected issues. */
					_n( '%d Issue (Failed)', '%d Issues (Failed)', $count, 'rtcamp-amc-copilot' ),
					$count
				);

				echo '<span class="amc-status-badge amc-status-badge--fail" style="background:#fee2e2;color:#991b1b;padding:3px 10px;border-radius:9999px;font-weight:600;font-size:11px;display:inline-flex;align-items:center;gap:5px;border:1px solid #f87171;"><span style="width:7px;height:7px;border-radius:50%;background:#dc2626;"></span> ' . esc_html( $badge_text ) . '</span>';

				if ( [] !== $failed_links ) {
					$rendered_links = [];
					foreach ( $failed_links as $title => $url ) {
						$rendered_links[] = sprintf(
							'<a href="%1$s" target="_blank" rel="noopener noreferrer" style="color:#b91c1c;text-decoration:underline;text-underline-offset:2px;">%2$s ↗</a>',
							esc_url( $url ),
							esc_html( $title )
						);
					}

					echo '<div style="font-size:11px;color:#dc2626;margin-top:4px;font-weight:500;">' . wp_kses_post( implode( ', ', $rendered_links ) ) . '</div>';
				}
			} else {
				echo '<span class="amc-status-badge amc-status-badge--pass" style="background:#dcfce7;color:#166534;padding:3px 10px;border-radius:9999px;font-weight:600;font-size:11px;display:inline-flex;align-items:center;gap:5px;border:1px solid #86efac;"><span style="width:7px;height:7px;border-radius:50%;background:#16a34a;"></span> ' . esc_html__( 'All Clear', 'rtcamp-amc-copilot' ) . '</span>';
			}
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_editor_supports(): array {
		return [ 'title', 'editor', 'revisions', 'author', 'custom-fields' ];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_custom_options(): array {
		return [
			'public'                => false,
			'publicly_queryable'    => false,
			'exclude_from_search'   => true,
			'has_archive'           => false,
			'rewrite'               => false,
			'query_var'             => false,
			'show_ui'               => true,
			'show_in_menu'          => true,
			'show_in_rest'          => true,
			'rest_base'             => 'amc-reports',
			'rest_controller_class' => ReportRestController::class,
			'capability_type'       => [ 'amc_report', 'amc_reports' ],
			'capabilities'          => [
				'read' => self::READ_CAP,
			],
			'map_meta_cap'          => true,
			'template'              => self::template(),
			'template_lock'         => false,
		];
	}

	/**
	 * Default block template for a new report: every section block, in report
	 * order, locked against moving and removing.
	 *
	 * Built from SnapshotSchema so the template, the snapshot and the blocks can
	 * never disagree about which sections exist. Lane D2 registers the blocks;
	 * until one is registered the editor shows a placeholder for it.
	 *
	 * @return list<array{0: string, 1: array<string, mixed>}>
	 */
	public static function template(): array {
		$locked = [
			'lock' => [
				'move'   => true,
				'remove' => true,
			],
		];

		$template = [];

		foreach ( SnapshotSchema::sections() as $section ) {
			$template[] = [ $section['block'], $locked ];
		}

		return $template;
	}

	/**
	 * {@inheritDoc}
	 */
	public function after_register(): void {
		foreach ( self::META as $key => $type ) {
			register_post_meta(
				self::SLUG,
				$key,
				[
					'type'          => $type,
					'single'        => true,
					'show_in_rest'  => true,
					'default'       => '',
					'auth_callback' => static fn (): bool => current_user_can( Capabilities::MANAGE ),
				]
			);
		}
	}

	/**
	 * Grant the report primitives.
	 *
	 * Engineers (amc_manage) get every primitive. Client administrators
	 * (amc_view_reports) get only the read primitive: core maps reading a
	 * published report to it, and reading a draft to edit_post, which they lack.
	 *
	 * @param array<string, bool> $allcaps User's capabilities.
	 *
	 * @return array<string, bool>
	 */
	public function map_caps( array $allcaps ): array {
		$can_manage = ! empty( $allcaps[ Capabilities::MANAGE ] );

		if ( $can_manage || ! empty( $allcaps[ Capabilities::VIEW_REPORTS ] ) ) {
			$allcaps[ self::READ_CAP ] = true;
		}

		if ( ! $can_manage ) {
			return $allcaps;
		}

		foreach ( [ 'edit', 'edit_others', 'edit_private', 'edit_published', 'publish', 'read_private', 'delete', 'delete_others', 'delete_private', 'delete_published' ] as $cap ) {
			$allcaps[ "{$cap}_amc_reports" ] = true;
		}

		return $allcaps;
	}

	/**
	 * Tell crawlers not to index report REST responses.
	 *
	 * @param \WP_HTTP_Response|\WP_Error $response Response.
	 * @param \WP_REST_Server             $server   Server.
	 * @param \WP_REST_Request            $request  Request.
	 *
	 * @return \WP_HTTP_Response|\WP_Error
	 */
	public static function noindex_headers( mixed $response, \WP_REST_Server $server, \WP_REST_Request $request ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Filter signature.
		$route = $request->get_route();

		if ( $response instanceof \WP_HTTP_Response && ( str_starts_with( $route, '/wp/v2/amc-reports' ) || str_starts_with( $route, '/amc/v1/reports' ) ) ) {
			$response->header( 'X-Robots-Tag', 'noindex, nofollow' );
		}

		return $response;
	}
}
