<?php
/**
 * Routes: /reports, /reports/generate, /reports/{id}/regenerate,
 * /reports/{id}/snapshot, /saved-fields.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Rest;

use AMC\Core\Capabilities;
use AMC\Reports\ClientView;
use AMC\Reports\FieldCatalogue;
use AMC\Reports\Generator;
use AMC\Reports\ReportPostType;
use AMC\Reports\SavedFields;
use AMC\Reports\SnapshotSchema;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class ReportsController
 *
 * Owner: lane D1.
 *
 * GET  /amc/v1/reports                    amc_view_reports users get published only.
 * POST /amc/v1/reports/generate           Creates the draft and QUEUES the snapshot build (cron),
 *                                         201 Report with build_status 'pending'. 409
 *                                         `amc_report_exists` when the period already has a report.
 * POST /amc/v1/reports/{id}/regenerate    Queues a snapshot rebuild of a DRAFT; keeps engineer
 *                                         fields, overrides and custom sections. 409 when published.
 * GET  /amc/v1/reports/{id}/snapshot      Published reports for readers; drafts for engineers only.
 * GET  /amc/v1/saved-fields               Reads amc_saved_fields.
 * POST /amc/v1/saved-fields               Merges and saves; send null to clear a field.
 *
 * Report adds `build_status` ('pending' | 'ready' | 'failed' | '') to the frozen shape, so the
 * console can poll a queued build.
 *
 * Editing and publishing a report use the block editor and core
 * /wp/v2/amc-reports routes; this controller does not duplicate them.
 *
 * @since 0.1.0
 */
final class ReportsController extends AbstractController {

	/**
	 * Owning lane.
	 */
	public const OWNER = 'D1';

	/**
	 * Option holding carried-forward Saved field values.
	 */
	public const SAVED_FIELDS_OPTION = SavedFields::OPTION;

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		$id_arg = [
			'id' => [
				'type'     => 'integer',
				'required' => true,
			],
		];

		$this->route(
			'/reports',
			self::readable(),
			[ $this, 'list_reports' ],
			[ $this, 'view_reports' ],
			array_merge(
				$this->pagination_args(),
				[
					'status' => [
						'type'    => 'string',
						'enum'    => [ 'any', 'draft', 'publish' ],
						'default' => 'any',
					],
				]
			)
		);

		$this->route(
			'/reports/generate',
			self::creatable(),
			[ $this, 'generate' ],
			[ $this, 'manage' ],
			[
				'period_type' => [
					'type'     => 'string',
					'enum'     => [ 'month', 'quarter', 'year', 'custom' ],
					'required' => true,
				],
				'start'       => [
					'type'        => 'string',
					'format'      => 'date',
					'description' => 'Y-m-d. Required for custom; optional otherwise (defaults to the previous period).',
				],
				'end'         => [
					'type'   => 'string',
					'format' => 'date',
				],
			]
		);

		$this->route( '/reports/(?P<id>\d+)/regenerate', self::creatable(), [ $this, 'regenerate' ], [ $this, 'manage' ], $id_arg );
		$this->route( '/reports/(?P<id>\d+)/snapshot', self::readable(), [ $this, 'get_snapshot' ], [ $this, 'view_reports' ], $id_arg );

		$this->route( '/saved-fields', self::readable(), [ $this, 'get_saved_fields' ], [ $this, 'manage' ] );
		$this->route(
			'/saved-fields',
			self::creatable(),
			[ $this, 'update_saved_fields' ],
			[ $this, 'manage' ],
			[
				'fields' => [
					'type'     => 'object',
					'required' => true,
				],
			]
		);
	}

	/**
	 * GET /reports → list<Report>.
	 *
	 * Report: { id, title, status, report_id, period_type, period_start,
	 *           period_end, environment, author: { id, name }, modified,
	 *           edit_url: string|null, view_url: string|null }
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function list_reports( WP_REST_Request $request ): WP_REST_Response {
		$per_page = (int) $request->get_param( 'per_page' );
		$status   = (string) $request->get_param( 'status' );

		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			$status = 'publish';
		}

		$query = new \WP_Query(
			[
				'post_type'      => ReportPostType::SLUG,
				'post_status'    => 'any' === $status ? [ 'draft', 'publish', 'pending', 'future' ] : $status,
				'posts_per_page' => $per_page,
				'paged'          => (int) $request->get_param( 'page' ),
				'orderby'        => 'date',
				'order'          => 'DESC',
			]
		);

		$items = array_map( [ $this, 'format_report' ], array_filter( $query->posts, static fn ( $post ): bool => $post instanceof WP_Post ) );

		return $this->paginated( array_values( $items ), (int) $query->found_posts, $per_page );
	}

	/**
	 * POST /reports/generate → 201 Report.
	 *
	 * Creates the draft report now and queues the slow part (the snapshot) for
	 * cron, so the request returns quickly. Poll GET /reports until the
	 * report's `build_status` is `ready`.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function generate( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$generator = new Generator();
		$period    = $generator->resolve_period(
			(string) $request->get_param( 'period_type' ),
			$this->date_param( $request, 'start' ),
			$this->date_param( $request, 'end' )
		);

		if ( $period instanceof \WP_Error ) {
			return $period;
		}

		$post_id = $generator->create( $period, get_current_user_id() );

		if ( $post_id instanceof \WP_Error ) {
			return $post_id;
		}

		$generator->queue_build( $post_id );

		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return new \WP_Error( 'amc_not_found', __( 'Report not found.', 'rtcamp-amc-copilot' ), [ 'status' => 404 ] );
		}

		$response = new WP_REST_Response( $this->format_report( $post ), 201 );
		$response->header( 'Location', rest_url( 'wp/v2/amc-reports/' . $post_id ) );

		return $response;
	}

	/**
	 * POST /reports/{id}/regenerate → Report. Replaces the snapshot, keeps
	 * Engineer fields, overrides and custom sections.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function regenerate( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$generator = new Generator();
		$post      = $generator->guard_regenerable( (int) $request->get_param( 'id' ) );

		if ( $post instanceof \WP_Error ) {
			return $post;
		}

		$generator->queue_build( $post->ID );

		return new WP_REST_Response( $this->format_report( $post ) );
	}

	/**
	 * GET /reports/{id}/snapshot → { id, taken_at, snapshot } (SnapshotSchema format).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_snapshot( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$post = get_post( (int) $request->get_param( 'id' ) );

		if ( ! $post instanceof WP_Post || ReportPostType::SLUG !== $post->post_type ) {
			return new \WP_Error( 'amc_not_found', __( 'Report not found.', 'rtcamp-amc-copilot' ), [ 'status' => 404 ] );
		}

		if ( 'publish' !== $post->post_status && ! current_user_can( Capabilities::MANAGE ) ) {
			return new \WP_Error( 'amc_forbidden', __( 'This report is not published.', 'rtcamp-amc-copilot' ), [ 'status' => 403 ] );
		}

		$snapshot = SnapshotSchema::for_post( $post->ID );

		$response = [
			'id'       => $post->ID,
			'taken_at' => $snapshot['taken_at'],
			'snapshot' => $snapshot,
		];

		if ( current_user_can( Capabilities::MANAGE ) ) {
			// Saved fields carried forward, and the ones this report changes (shown before publish).
			$response['saved_fields'] = SavedFields::review( $post->ID );
		}

		return new WP_REST_Response( $response );
	}

	/**
	 * GET /saved-fields → { fields: { section: { field: string|null } }, schema: { section: list<field> } }.
	 */
	public function get_saved_fields(): WP_REST_Response {
		return new WP_REST_Response(
			[
				'fields' => SavedFields::all(),
				'schema' => FieldCatalogue::saved(),
			]
		);
	}

	/**
	 * POST /saved-fields { fields } → same shape as GET.
	 *
	 * Only the fields sent are changed; `null` or an empty string clears one.
	 * Unknown sections and fields are ignored.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function update_saved_fields( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$fields = $request->get_param( 'fields' );

		if ( ! is_array( $fields ) ) {
			return new \WP_Error( 'rest_invalid_param', __( 'fields must be an object of section => field => value.', 'rtcamp-amc-copilot' ), [ 'status' => 400 ] );
		}

		SavedFields::update( $fields );

		return $this->get_saved_fields();
	}

	/**
	 * A Y-m-d request parameter, or null.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $name    Parameter name.
	 */
	private function date_param( WP_REST_Request $request, string $name ): ?string {
		$value = $request->get_param( $name );

		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * Post → Report shape.
	 *
	 * @param WP_Post $post Report post.
	 *
	 * @return array<string, mixed>
	 */
	public function format_report( WP_Post $post ): array {
		$author = get_userdata( (int) $post->post_author );

		return [
			'id'           => $post->ID,
			'title'        => get_the_title( $post ),
			'status'       => $post->post_status,
			'report_id'    => (string) get_post_meta( $post->ID, '_amc_report_id', true ),
			'period_type'  => (string) get_post_meta( $post->ID, '_amc_period_type', true ),
			'period_start' => (string) get_post_meta( $post->ID, '_amc_period_start', true ),
			'period_end'   => (string) get_post_meta( $post->ID, '_amc_period_end', true ),
			'environment'  => (string) get_post_meta( $post->ID, '_amc_environment', true ),
			'author'       => [
				'id'   => (int) $post->post_author,
				'name' => $author instanceof \WP_User ? $author->display_name : '',
			],
			'modified'     => self::iso( $post->post_modified_gmt ),
			'edit_url'     => current_user_can( 'edit_post', $post->ID ) ? get_edit_post_link( $post->ID, 'raw' ) : null,
			// The read-only view page (ClientView). Drafts can be previewed by engineers only.
			'view_url'     => null !== ClientView::visible_report( $post->ID ) ? ClientView::view_url( $post->ID ) : null,
			'build_status' => (string) get_post_meta( $post->ID, '_amc_build_status', true ),
		];
	}
}
