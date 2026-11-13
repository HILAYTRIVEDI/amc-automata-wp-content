<?php
/**
 * Tests for who may do what with amc_report posts.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Reports;

use AMC\Core\Capabilities;
use AMC\Reports\ReportPostType;
use WP_REST_Request;

/**
 * Class ReportCapabilitiesTest
 *
 * Three kinds of user: an engineer (`amc_manage`), a client administrator
 * (administrator role with `amc_view_reports`) and a plain administrator whose
 * account does NOT have `amc_view_reports`.
 */
final class ReportCapabilitiesTest extends ReportsTestCase {

	/**
	 * Draft report ID.
	 *
	 * @var int
	 */
	private int $draft = 0;

	/**
	 * Published report ID.
	 *
	 * @var int
	 */
	private int $published = 0;

	/**
	 * Administrator without amc_view_reports.
	 *
	 * @var int
	 */
	private int $plain_admin = 0;

	/**
	 * {@inheritDoc}
	 */
	public function set_up(): void {
		parent::set_up();

		$this->draft     = self::factory()->post->create(
			[
				'post_type'   => ReportPostType::SLUG,
				'post_status' => 'draft',
				'post_author' => $this->engineer,
			]
		);
		$this->published = self::factory()->post->create(
			[
				'post_type'   => ReportPostType::SLUG,
				'post_status' => 'publish',
				'post_author' => $this->engineer,
			]
		);

		// An administrator who does not hold amc_view_reports (a user-level opt-out).
		$this->plain_admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		get_userdata( $this->plain_admin )->add_cap( Capabilities::VIEW_REPORTS, false );
	}

	public function test_engineer_can_create_edit_publish_and_read_drafts(): void {
		$this->assertTrue( user_can( $this->engineer, 'edit_amc_reports' ), 'create / list' );
		$this->assertTrue( user_can( $this->engineer, 'publish_amc_reports' ), 'publish' );
		$this->assertTrue( user_can( $this->engineer, 'edit_post', $this->draft ), 'edit a draft' );
		$this->assertTrue( user_can( $this->engineer, 'read_post', $this->draft ), 'read a draft' );
		$this->assertTrue( user_can( $this->engineer, 'edit_post', $this->published ), 'edit a published report' );
		$this->assertTrue( user_can( $this->engineer, 'read_post', $this->published ), 'read a published report' );
		$this->assertTrue( user_can( $this->engineer, 'delete_post', $this->draft ), 'delete' );
	}

	public function test_another_engineer_can_edit_a_draft_they_did_not_write(): void {
		$other = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Capabilities::grant( $other );

		$this->assertTrue( user_can( $other, 'edit_post', $this->draft ) );
		$this->assertTrue( user_can( $other, 'publish_post', $this->draft ) );
	}

	public function test_client_administrator_reads_published_reports_only(): void {
		$this->assertTrue( user_can( $this->client, 'read_post', $this->published ), 'published: allowed' );
		$this->assertFalse( user_can( $this->client, 'read_post', $this->draft ), 'draft: not allowed' );
	}

	public function test_client_administrator_cannot_edit_publish_or_delete(): void {
		$this->assertFalse( user_can( $this->client, 'edit_amc_reports' ), 'no editor' );
		$this->assertFalse( user_can( $this->client, 'edit_post', $this->draft ) );
		$this->assertFalse( user_can( $this->client, 'edit_post', $this->published ) );
		$this->assertFalse( user_can( $this->client, 'publish_amc_reports' ) );
		$this->assertFalse( user_can( $this->client, 'delete_post', $this->published ) );
		$this->assertFalse( user_can( $this->client, 'read_private_amc_reports' ) ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom post type capability.
		$this->assertFalse( user_can( $this->client, Capabilities::MANAGE ) );
	}

	public function test_administrator_without_view_reports_can_do_nothing(): void {
		$this->assertTrue( user_can( $this->plain_admin, 'manage_options' ), 'still a site administrator' );

		foreach ( [ $this->published, $this->draft ] as $post_id ) {
			$this->assertFalse( user_can( $this->plain_admin, 'read_post', $post_id ), 'read' );
			$this->assertFalse( user_can( $this->plain_admin, 'edit_post', $post_id ), 'edit' );
		}

		$this->assertFalse( user_can( $this->plain_admin, ReportPostType::READ_CAP ) );
	}

	public function test_ordinary_users_cannot_read_a_published_report(): void {
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$this->assertFalse( user_can( $subscriber, 'read_post', $this->published ), 'the core "read" capability must not open reports' );
	}

	public function test_post_type_is_private_and_never_on_the_front_end(): void {
		$type = get_post_type_object( ReportPostType::SLUG );

		$this->assertFalse( $type->public );
		$this->assertFalse( $type->publicly_queryable );
		$this->assertTrue( $type->exclude_from_search );
		$this->assertFalse( $type->has_archive );
		$this->assertFalse( $type->rewrite );
		$this->assertSame( 'read_amc_reports', $type->cap->read );
	}

	public function test_template_has_every_section_in_order_and_locked(): void {
		$template = ReportPostType::template();
		$names    = array_column( $template, 0 );

		$this->assertCount( 18, $template );
		$this->assertSame( 'amc/section-overview', $names[0] );
		$this->assertSame( 'amc/section-plugins-themes', $names[3] );
		$this->assertSame( 'amc/section-engineer-notes', $names[17] );
		$this->assertSame(
			[
				'move'   => true,
				'remove' => true,
			],
			$template[5][1]['lock']
		);
		$this->assertFalse( get_post_type_object( ReportPostType::SLUG )->template_lock );
	}

	public function test_rest_reports_are_not_served_to_anonymous_requests(): void {
		wp_set_current_user( 0 );

		$collection = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/amc-reports' ) );
		$single     = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/amc-reports/' . $this->published ) );

		$this->assertSame( 401, $collection->get_status() );
		$this->assertContains( $single->get_status(), [ 401, 403 ] );
	}

	public function test_rest_client_administrator_sees_published_only(): void {
		wp_set_current_user( $this->client );

		$list = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/amc-reports' ) );
		$ids  = wp_list_pluck( $list->get_data(), 'id' );

		$this->assertSame( 200, $list->get_status() );
		$this->assertContains( $this->published, $ids );
		$this->assertNotContains( $this->draft, $ids );

		$this->assertSame( 200, rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/amc-reports/' . $this->published ) )->get_status() );
		$this->assertContains( rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/amc-reports/' . $this->draft ) )->get_status(), [ 401, 403, 404 ] );

		$edit = new WP_REST_Request( 'GET', '/wp/v2/amc-reports/' . $this->published );
		$edit->set_param( 'context', 'edit' );
		$this->assertContains( rest_do_request( $edit )->get_status(), [ 401, 403 ], 'the editor context is for engineers' );

		$create = new WP_REST_Request( 'POST', '/wp/v2/amc-reports' );
		$create->set_body_params( [ 'title' => 'Mine' ] );
		$this->assertContains( rest_do_request( $create )->get_status(), [ 401, 403 ] );
	}

	public function test_rest_administrator_without_view_reports_is_refused(): void {
		wp_set_current_user( $this->plain_admin );

		$this->assertContains( rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/amc-reports' ) )->get_status(), [ 401, 403 ] );
		$this->assertContains( rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/amc-reports/' . $this->published ) )->get_status(), [ 401, 403 ] );
	}

	public function test_rest_engineer_sees_drafts_and_can_edit(): void {
		wp_set_current_user( $this->engineer );

		$request = new WP_REST_Request( 'GET', '/wp/v2/amc-reports' );
		$request->set_param( 'status', 'draft,publish' );
		$ids = wp_list_pluck( rest_do_request( $request )->get_data(), 'id' );

		$this->assertContains( $this->draft, $ids );
		$this->assertContains( $this->published, $ids );

		$edit = new WP_REST_Request( 'GET', '/wp/v2/amc-reports/' . $this->draft );
		$edit->set_param( 'context', 'edit' );
		$this->assertSame( 200, rest_do_request( $edit )->get_status() );
	}

	public function test_rest_responses_are_marked_noindex(): void {
		wp_set_current_user( $this->engineer );

		$request  = new WP_REST_Request( 'GET', '/amc/v1/reports' );
		$response = rest_do_request( $request );
		// The server applies this filter when it serves a request.
		$response = apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request );

		$this->assertSame( 'noindex, nofollow', $response->get_headers()['X-Robots-Tag'] );
	}
}
