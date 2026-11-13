<?php
/**
 * The `amc_report` post type.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports;

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
