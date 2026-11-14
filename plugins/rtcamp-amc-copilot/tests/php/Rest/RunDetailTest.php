<?php
/**
 * Tests for GET /amc/v1/runs/{id}?detail=1 (the Run detail page).
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Rest;

use AMC\Core\Capabilities;
use AMC\Storage\RunsRepository;
use AMC\Tests\TestCase;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Class RunDetailTest
 */
final class RunDetailTest extends TestCase {

	/**
	 * Engineer user ID.
	 *
	 * @var int
	 */
	private int $engineer;

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->engineer = self::factory()->user->create(
			[
				'role'         => 'administrator',
				'display_name' => 'Ada Engineer',
			]
		);
		Capabilities::grant( $this->engineer );
		wp_set_current_user( $this->engineer );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * GET /runs/{id}.
	 *
	 * @param int  $id     Run ID.
	 * @param bool $detail Ask for the detail fields.
	 *
	 * @return array<string, mixed>
	 */
	private function get_run( int $id, bool $detail = true ): array {
		$request = new WP_REST_Request( 'GET', "/amc/v1/runs/{$id}" );
		if ( $detail ) {
			$request->set_param( 'detail', true );
		}

		return (array) rest_get_server()->dispatch( $request )->get_data();
	}

	/**
	 * A finished run.
	 *
	 * @param string               $check_id Check.
	 * @param string               $status   Status.
	 * @param array<string, mixed> $data     Data.
	 * @param int                  $user_id  User who triggered it.
	 */
	private function make_run( string $check_id, string $status, array $data, int $user_id = 0 ): int {
		$runs = new RunsRepository();
		$id   = $runs->create_running( $check_id, $user_id > 0 ? 'manual' : 'cron', $user_id );
		$runs->finish( $id, $status, "{$status} summary", $data, 42 );

		return $id;
	}

	public function test_without_detail_the_poll_shape_is_unchanged(): void {
		$id  = $this->make_run( 'updates.core', 'pass', [ 'a' => 1 ] );
		$run = $this->get_run( $id, false );

		$this->assertSame( [ 'a' => 1 ], $run['data'] );
		$this->assertArrayHasKey( 'findings', $run );
		$this->assertArrayNotHasKey( 'history', $run, 'polls stay light' );
		$this->assertArrayNotHasKey( 'previous', $run );
	}

	public function test_detail_links_the_run_to_its_neighbours_and_previous_result(): void {
		$first  = $this->make_run( 'updates.core', 'pass', [ 'count' => 1 ] );
		$other  = $this->make_run( 'updates.plugins', 'pass', [] );
		$middle = $this->make_run( 'updates.core', 'warn', [ 'count' => 2 ], $this->engineer );
		$last   = $this->make_run( 'updates.core', 'pass', [ 'count' => 3 ] );

		$run = $this->get_run( $middle );

		$this->assertSame( $first, $run['older_id'], 'older run of the same check, skipping other checks' );
		$this->assertSame( $last, $run['newer_id'] );
		$this->assertSame( $first, $run['previous']['id'], 'previous result to compare with' );
		$this->assertSame( [ 'count' => 1 ], $run['previous']['data'] );
		$this->assertSame( [ $last, $middle, $first ], array_column( $run['history'], 'id' ), 'newest first, same check only' );
		$this->assertNotContains( $other, array_column( $run['history'], 'id' ) );
		$this->assertArrayNotHasKey( 'data', $run['history'][0], 'history rows carry no data' );

		$this->assertSame(
			[
				'id'   => $this->engineer,
				'name' => 'Ada Engineer',
			],
			$run['user']
		);
		$this->assertSame( 'twicedaily', $run['schedule'] );
		$this->assertIsInt( $run['queue_ms'] );
	}

	public function test_the_first_run_of_a_check_has_no_older_or_previous(): void {
		$id  = $this->make_run( 'updates.core', 'pass', [] );
		$run = $this->get_run( $id );

		$this->assertNull( $run['older_id'] );
		$this->assertNull( $run['newer_id'] );
		$this->assertNull( $run['previous'] );
		$this->assertNull( $run['user'], 'cron runs have no user' );
	}

	public function test_error_runs_are_skipped_as_the_previous_result(): void {
		$good  = $this->make_run( 'updates.core', 'pass', [ 'ok' => true ] );
		$error = $this->make_run(
			'updates.core',
			RunsRepository::STATUS_ERROR,
			[
				'exception' => 'RuntimeException',
				'message'   => 'boom',
			]
		);
		$id    = $this->make_run( 'updates.core', 'pass', [ 'ok' => true ] );

		$run = $this->get_run( $id );

		$this->assertSame( $error, $run['older_id'], 'neighbours include error runs' );
		$this->assertSame( $good, $run['previous']['id'], 'but the comparison uses the last real result' );
	}

	public function test_detail_for_another_sites_run_is_not_found(): void {
		$request = new WP_REST_Request( 'GET', '/amc/v1/runs/999999' );
		$request->set_param( 'detail', true );

		$this->assertSame( 404, rest_get_server()->dispatch( $request )->get_status() );
	}
}
