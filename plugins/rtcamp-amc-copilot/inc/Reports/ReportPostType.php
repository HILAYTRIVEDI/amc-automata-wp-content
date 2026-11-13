<?php
/**
 * The `amc_report` post type (minimal Wave 0 registration).
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
 * Owner: lane D1 (Wave 0 ships the minimum for section blocks to render).
 *
 * - Private: not public, not queryable, not searchable; shown in wp-admin
 *   and in REST (block editor).
 * - Custom capability type `amc_report` with map_meta_cap. Wave 0 maps
 *   every amc_report primitive cap to `amc_manage` (map_caps()); D1 refines
 *   read access for `amc_view_reports` (published reports only).
 * - Post meta (all single, registered for REST, editable with amc_manage):
 *   _amc_period_type, _amc_period_start, _amc_period_end, _amc_environment,
 *   _amc_snapshot (JSON string, see SnapshotSchema), _amc_snapshot_taken_at,
 *   _amc_report_id (e.g. AMC-NWT-2026-10).
 * - Template: section blocks in report order, each locked (move+remove).
 *   template_lock is false so custom sections can be inserted anywhere.
 *   D2 adds its blocks to template() as it builds them.
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
	];

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
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_rest'        => true,
			'rest_base'           => 'amc-reports',
			'capability_type'     => [ 'amc_report', 'amc_reports' ],
			'map_meta_cap'        => true,
			'template'            => self::template(),
			'template_lock'       => false,
		];
	}

	/**
	 * Default block template for a new report.
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

		return [
			[ 'amc/section-overview', $locked ],
		];
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
	 * Grant every amc_report primitive capability to users with amc_manage.
	 *
	 * @param array<string, bool> $allcaps User's capabilities.
	 *
	 * @return array<string, bool>
	 */
	public function map_caps( array $allcaps ): array {
		if ( empty( $allcaps[ Capabilities::MANAGE ] ) ) {
			return $allcaps;
		}

		foreach ( [ 'edit', 'edit_others', 'edit_private', 'edit_published', 'publish', 'read_private', 'delete', 'delete_others', 'delete_private', 'delete_published' ] as $cap ) {
			$allcaps[ "{$cap}_amc_reports" ] = true;
		}

		return $allcaps;
	}
}
