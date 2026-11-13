<?php
/**
 * What happens when a report is published, and the Saved-field review data.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports;

use AMC\Core\Capabilities;
use WP_Post;
use WP_REST_Request;
use rtCamp\WPPrimitives\Contracts\Interfaces\Registrable;

/**
 * Class ReportPublishing
 *
 * - On the transition to `publish`: changed Saved fields are written back to
 *   `amc_saved_fields` (SavedFields::write_back()) and `amc_report_published`
 *   fires with the post ID (ReportEmails sends the client email from it).
 * - Before publish: the REST field `amc_saved_fields_review` on
 *   `/wp/v2/amc-reports/{id}` (edit context, engineers only) gives the editor
 *   `{ previous, changes }` so it can show the previous value next to every
 *   changed Saved field (plan Risks: "show the previous value next to any
 *   changed Saved field before publish"). Lane D2 renders it. `previous` holds
 *   the carried-forward value of every Saved field; `changes` only the fields
 *   that differ in this report. The values are computed from the SAVED post, so
 *   the editor refreshes them after each save.
 *
 * @since 0.1.0
 */
final class ReportPublishing implements Registrable {

	/**
	 * REST field name.
	 */
	public const REST_FIELD = 'amc_saved_fields_review';

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'transition_post_status', [ $this, 'on_transition' ], 10, 3 );
		add_action( 'rest_api_init', [ $this, 'register_rest_field' ] );
	}

	/**
	 * Write back Saved fields and announce the report when it becomes published.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public function on_transition( string $new_status, string $old_status, WP_Post $post ): void {
		if ( ReportPostType::SLUG !== $post->post_type || 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}

		SavedFields::write_back( $post->ID );

		/**
		 * Fires when a report becomes published.
		 *
		 * Lane A2 sends the client email from this (ReportEmails::published()
		 * supplies the content).
		 *
		 * @param int $post_id Report ID.
		 */
		do_action( 'amc_report_published', $post->ID );
	}

	/**
	 * Add the Saved-field review to the core REST report.
	 */
	public function register_rest_field(): void {
		register_rest_field(
			ReportPostType::SLUG,
			self::REST_FIELD,
			[
				'get_callback' => static function ( array $report, string $field, WP_REST_Request $request ): ?array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- REST callback signature.
					$post_id = (int) ( $report['id'] ?? 0 );

					if ( $post_id <= 0 || ! current_user_can( Capabilities::MANAGE ) ) {
						return null;
					}

					return SavedFields::review( $post_id );
				},
				'schema'       => [
					'description' => __( 'Saved fields carried forward, and the ones this report changes.', 'rtcamp-amc-copilot' ),
					'type'        => [ 'object', 'null' ],
					'context'     => [ 'edit' ],
					'readonly'    => true,
				],
			]
		);
	}
}
