<?php
/**
 * Tests for the /amc/v1 report routes.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Reports;

use AMC\Reports\Generator;
use AMC\Reports\ReportPostType;
use AMC\Reports\SnapshotSchema;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Class ReportsControllerTest
 */
final class ReportsControllerTest extends ReportsTestCase {

	/**
	 * {@inheritDoc}
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		wp_unschedule_hook( Generator::BUILD_HOOK );
	}

	/**
	 * {@inheritDoc}
	 */
	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * Dispatch a request.
	 *
	 * @param string               $method Method.
	 * @param string               $path   Path.
	 * @param array<string, mixed> $params Params.
	 */
	private function request( string $method, string $path, array $params = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $path );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Create a report post directly.
	 *
	 * @param string $status Post status.
	 * @param string $title  Title.
	 */
	private function report( string $status, string $title = 'A report' ): int {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => ReportPostType::SLUG,
				'post_status' => $status,
				'post_title'  => $title,
				'post_author' => $this->engineer,
			]
		);

		update_post_meta( $post_id, '_amc_report_id', 'AMC-X-' . $post_id );
		update_post_meta( $post_id, '_amc_period_type', 'month' );
		update_post_meta( $post_id, '_amc_period_start', '2026-10-01' );
		update_post_meta( $post_id, '_amc_period_end', '2026-10-31' );
		update_post_meta( $post_id, '_amc_environment', 'production' );

		return $post_id;
	}

	public function test_list_shows_clients_published_reports_only_and_engineers_everything(): void {
		$published = $this->report( 'publish', 'Published one' );
		$draft     = $this->report( 'draft', 'Draft one' );

		wp_set_current_user( $this->client );
		$client_ids = array_column( $this->request( 'GET', '/amc/v1/reports', [ 'status' => 'draft' ] )->get_data(), 'id' );

		$this->assertSame( [ $published ], $client_ids, 'a client asking for drafts still only gets published reports' );

		wp_set_current_user( $this->engineer );
		$response = $this->request( 'GET', '/amc/v1/reports' );
		$all      = array_column( $response->get_data(), null, 'id' );

		$this->assertArrayHasKey( $published, $all );
		$this->assertArrayHasKey( $draft, $all );
		$this->assertSame( '2', $response->get_headers()['X-WP-Total'] );

		$row = $all[ $published ];

		foreach ( [ 'id', 'title', 'status', 'report_id', 'period_type', 'period_start', 'period_end', 'environment', 'author', 'modified', 'edit_url', 'view_url' ] as $key ) {
			$this->assertArrayHasKey( $key, $row, $key );
		}

		$this->assertSame( 'AMC-X-' . $published, $row['report_id'] );
		$this->assertSame( 'Erin Engineer', $row['author']['name'] );
		$this->assertStringContainsString( 'page=amc-reports&report=' . $published, $row['view_url'] );
		$this->assertNotNull( $row['edit_url'] );
	}

	public function test_a_client_sees_a_view_url_and_no_edit_url(): void {
		$published = $this->report( 'publish' );

		wp_set_current_user( $this->client );
		$row = $this->request( 'GET', '/amc/v1/reports' )->get_data()[0];

		$this->assertSame( $published, $row['id'] );
		$this->assertNull( $row['edit_url'] );
		$this->assertSame( admin_url( 'admin.php?page=amc-reports&report=' . $published ), $row['view_url'] );
	}

	public function test_generate_creates_the_draft_and_queues_the_snapshot_instead_of_building_it(): void {
		wp_set_current_user( $this->engineer );

		$response = $this->request(
			'POST',
			'/amc/v1/reports/generate',
			[
				'period_type' => 'month',
				'start'       => '2026-10-05',
			]
		);
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'draft', $data['status'] );
		$this->assertSame( 'month', $data['period_type'] );
		$this->assertSame( '2026-10-01', $data['period_start'] );
		$this->assertSame( '2026-10-31', $data['period_end'] );
		$this->assertStringEndsWith( '-2026-10', $data['report_id'] );
		$this->assertSame( 'pending', $data['build_status'] );
		$this->assertSame( $this->engineer, $data['author']['id'] );
		$this->assertStringContainsString( '/amc-reports/' . $data['id'], $response->get_headers()['Location'] );

		$this->assertSame( '', get_post_meta( $data['id'], '_amc_snapshot_taken_at', true ), 'the slow part did not run inside the request' );
		$this->assertNotFalse( wp_next_scheduled( Generator::BUILD_HOOK, [ $data['id'] ] ), 'it is queued for cron' );
		$this->assertCount( 18, array_filter( parse_blocks( get_post( $data['id'] )->post_content ), static fn ( array $b ): bool => null !== $b['blockName'] ) );
	}

	public function test_generate_defaults_to_the_last_completed_period(): void {
		wp_set_current_user( $this->engineer );

		$data = $this->request( 'POST', '/amc/v1/reports/generate', [ 'period_type' => 'quarter' ] )->get_data();

		$this->assertSame( 'quarter', $data['period_type'] );
		$this->assertLessThan( gmdate( 'Y-m-d' ), $data['period_end'] );
	}

	public function test_generating_the_same_period_twice_is_a_409_and_makes_no_second_draft(): void {
		wp_set_current_user( $this->engineer );
		$params = [
			'period_type' => 'custom',
			'start'       => '2026-09-01',
			'end'         => '2026-09-15',
		];

		$first  = $this->request( 'POST', '/amc/v1/reports/generate', $params );
		$second = $this->request( 'POST', '/amc/v1/reports/generate', $params );

		$this->assertSame( 201, $first->get_status() );
		$this->assertSame( 409, $second->get_status() );
		$this->assertSame( 'amc_report_exists', $second->get_data()['code'] );
		$this->assertSame( $first->get_data()['id'], $second->get_data()['data']['existing_id'] );
		$this->assertCount(
			1,
			get_posts(
				[
					'post_type'   => ReportPostType::SLUG,
					'post_status' => 'any',
					'numberposts' => 50,
				]
			)
		);
	}

	public function test_generate_validates_the_period(): void {
		wp_set_current_user( $this->engineer );

		$this->assertSame( 400, $this->request( 'POST', '/amc/v1/reports/generate', [ 'period_type' => 'custom' ] )->get_status(), 'custom needs dates' );
		$this->assertSame(
			400,
			$this->request(
				'POST',
				'/amc/v1/reports/generate',
				[
					'period_type' => 'custom',
					'start'       => '2026-10-20',
					'end'         => '2026-10-01',
				]
			)->get_status()
		);
		$this->assertSame( 400, $this->request( 'POST', '/amc/v1/reports/generate', [ 'period_type' => 'weekly' ] )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/amc/v1/reports/generate', [] )->get_status() );
	}

	public function test_generate_and_regenerate_are_for_engineers_only(): void {
		$draft = $this->report( 'draft' );

		wp_set_current_user( $this->client );
		$this->assertSame( 403, $this->request( 'POST', '/amc/v1/reports/generate', [ 'period_type' => 'month' ] )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', "/amc/v1/reports/{$draft}/regenerate" )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/amc/v1/saved-fields', [ 'fields' => [] ] )->get_status() );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( 'POST', '/amc/v1/reports/generate', [ 'period_type' => 'month' ] )->get_status() );
		$this->assertSame( 401, $this->request( 'GET', '/amc/v1/saved-fields' )->get_status() );
	}

	public function test_regenerate_queues_a_rebuild_and_keeps_the_content(): void {
		wp_set_current_user( $this->engineer );

		$generated = $this->generator()->generate( $this->october(), $this->engineer );
		$content   = get_post( $generated )->post_content;

		$response = $this->request( 'POST', "/amc/v1/reports/{$generated}/regenerate" );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $generated, $response->get_data()['id'] );
		$this->assertSame( 'pending', $response->get_data()['build_status'] );
		$this->assertNotFalse( wp_next_scheduled( Generator::BUILD_HOOK, [ $generated ] ) );
		$this->assertSame( $content, get_post( $generated )->post_content );
	}

	public function test_regenerate_refuses_published_and_unknown_reports(): void {
		wp_set_current_user( $this->engineer );

		$published = $this->report( 'publish' );

		$this->assertSame( 409, $this->request( 'POST', "/amc/v1/reports/{$published}/regenerate" )->get_status() );
		$this->assertSame( 404, $this->request( 'POST', '/amc/v1/reports/999999/regenerate' )->get_status() );
		$this->assertSame( 404, $this->request( 'POST', '/amc/v1/reports/' . self::factory()->post->create() . '/regenerate' )->get_status() );
	}

	public function test_snapshot_is_served_to_clients_for_published_reports_only(): void {
		$published = $this->report( 'publish' );
		$draft     = $this->report( 'draft' );

		wp_set_current_user( $this->client );

		$ok = $this->request( 'GET', "/amc/v1/reports/{$published}/snapshot" );

		$this->assertSame( 200, $ok->get_status() );
		$this->assertCount( 18, $ok->get_data()['snapshot']['sections'] );
		$this->assertArrayNotHasKey( 'saved_fields', $ok->get_data(), 'the pre-publish review is for engineers' );
		$this->assertSame( 403, $this->request( 'GET', "/amc/v1/reports/{$draft}/snapshot" )->get_status() );

		wp_set_current_user( $this->engineer );

		$this->assertSame( 200, $this->request( 'GET', "/amc/v1/reports/{$draft}/snapshot" )->get_status() );
		$this->assertArrayHasKey( 'saved_fields', $this->request( 'GET', "/amc/v1/reports/{$draft}/snapshot" )->get_data() );
	}

	public function test_saved_fields_round_trip(): void {
		wp_set_current_user( $this->engineer );

		$initial = $this->request( 'GET', '/amc/v1/saved-fields' )->get_data();

		$this->assertSame( SnapshotSchema::saved_fields(), $initial['schema'] );
		$this->assertNull( $initial['fields']['overview']['client_name'] );

		$saved = $this->request(
			'POST',
			'/amc/v1/saved-fields',
			[
				'fields' => [
					'overview' => [
						'client_name' => 'Acme Ltd',
						'hours_used'  => 'engineer fields are not saved fields',
					],
					'bogus'    => [ 'a' => 'b' ],
				],
			]
		);

		$this->assertSame( 200, $saved->get_status() );
		$this->assertSame( 'Acme Ltd', $saved->get_data()['fields']['overview']['client_name'] );
		$this->assertArrayNotHasKey( 'bogus', $saved->get_data()['fields'] );
		$this->assertArrayNotHasKey( 'hours_used', $saved->get_data()['fields']['overview'] );
		$this->assertSame( $saved->get_data(), $this->request( 'GET', '/amc/v1/saved-fields' )->get_data() );

		$cleared = $this->request( 'POST', '/amc/v1/saved-fields', [ 'fields' => [ 'overview' => [ 'client_name' => null ] ] ] );

		$this->assertNull( $cleared->get_data()['fields']['overview']['client_name'] );
	}

	public function test_saved_fields_reject_a_non_object(): void {
		wp_set_current_user( $this->engineer );

		$this->assertSame( 400, $this->request( 'POST', '/amc/v1/saved-fields', [ 'fields' => 'nope' ] )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/amc/v1/saved-fields', [] )->get_status() );
	}
}
