<?php
/**
 * REST controller for `/wp/v2/amc-reports` that never serves reports to people
 * who may not read them.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports;

use WP_REST_Posts_Controller;

/**
 * Class ReportRestController
 *
 * Core's posts controller lets ANY request read a published post of a
 * `show_in_rest` post type, including anonymous ones. A report must only be
 * readable by users who hold the post type's `read` capability
 * (`read_amc_reports`: engineers and client administrators), so this controller
 * checks it first on every read. Writes keep core's own checks, which use the
 * custom `*_amc_reports` capabilities (engineers only).
 *
 * @since 0.1.0
 */
final class ReportRestController extends WP_REST_Posts_Controller {

	/**
	 * Collection reads need the report read capability, in every context.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return bool|\WP_Error
	 */
	public function get_items_permissions_check( $request ) { // phpcs:ignore SlevomatCodingStandard.TypeHints -- Signature of the parent method.
		if ( ! $this->can_read_reports() ) {
			return $this->forbidden();
		}

		return parent::get_items_permissions_check( $request );
	}

	/**
	 * A post is readable only when core would let this user read it AND the user
	 * holds the report read capability.
	 *
	 * @param \WP_Post $post Post.
	 *
	 * @return bool
	 */
	public function check_read_permission( $post ) { // phpcs:ignore SlevomatCodingStandard.TypeHints -- Signature of the parent method.
		if ( ! $this->can_read_reports() ) {
			return false;
		}

		// Drafts and other unpublished states need the meta capability, which a
		// client administrator does not have.
		if ( 'publish' !== $post->post_status && ! current_user_can( 'read_post', $post->ID ) ) {
			return false;
		}

		return parent::check_read_permission( $post );
	}

	/**
	 * Whether the current user holds the post type's read capability.
	 */
	private function can_read_reports(): bool {
		return current_user_can( ReportPostType::READ_CAP );
	}

	/**
	 * The standard "you cannot read reports" error.
	 */
	private function forbidden(): \WP_Error {
		return new \WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to read reports.', 'rtcamp-amc-copilot' ),
			[ 'status' => rest_authorization_required_code() ]
		);
	}
}
