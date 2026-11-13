<?php
/**
 * Tests for GET /changes.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Capture\Rest;

use AMC\Core\Capabilities;
use AMC\Storage\ChangesRepository;
use AMC\Tests\TestCase;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Class ChangesControllerTest
 */
final class ChangesControllerTest extends TestCase {

	/**
	 * Acting user.
	 *
	 * @var int
	 */
	private int $actor;

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->actor = self::factory()->user->create(
			[
				'role'         => 'administrator',
				'display_name' => 'Ada Engineer',
			]
		);
		Capabilities::grant( $this->actor );
		wp_set_current_user( $this->actor );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * Dispatch a GET /changes.
	 *
	 * @param array<string, mixed> $params Params.
	 */
	private function get_changes( array $params = [] ): \WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/amc/v1/changes' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Seed changes.
	 */
	private function seed(): void {
		global $wpdb;
		// Creating the test users already logged changes; start from an empty log.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', ( new ChangesRepository() )->table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$repo = new ChangesRepository();
		$repo->insert(
			[
				'type'        => 'plugin',
				'action'      => 'update',
				'item'        => 'akismet',
				'item_name'   => 'Akismet',
				'old_version' => '5.0',
				'new_version' => '5.1',
				'source'      => 'admin',
				'user_id'     => $this->actor,
				'created_at'  => '2026-11-01 10:00:00',
			]
		);
		$repo->insert(
			[
				'type'        => 'plugin',
				'action'      => 'update',
				'item'        => 'jetpack',
				'new_version' => '14.0',
				'source'      => 'deploy',
				'user_id'     => 0,
				'created_at'  => '2026-11-05 10:00:00',
			]
		);
		$repo->insert(
			[
				'type'       => 'user',
				'action'     => 'role',
				'item'       => 'bob',
				'source'     => 'cli',
				'user_id'    => 0,
				'created_at' => '2026-11-10 10:00:00',
			]
		);
	}

	public function test_shape_order_and_headers(): void {
		$this->seed();

		$response = $this->get_changes();
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '3', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '1', $response->get_headers()['X-WP-TotalPages'] );
		$this->assertSame( [ 'bob', 'jetpack', 'akismet' ], array_column( $data, 'item' ), 'Newest first' );
		$this->assertSame(
			[ 'id', 'type', 'action', 'item', 'item_name', 'old_version', 'new_version', 'source', 'user_id', 'created_at', 'user_display_name' ],
			array_keys( $data[2] )
		);
		$this->assertSame( '2026-11-01T10:00:00Z', $data[2]['created_at'] );
		$this->assertSame( 'Ada Engineer', $data[2]['user_display_name'] );
		$this->assertNull( $data[1]['user_display_name'], 'No user (deploy)' );
		$this->assertArrayNotHasKey( 'blog_id', $data[0] );
	}

	public function test_filters(): void {
		$this->seed();

		$this->assertSame( [ 'jetpack', 'akismet' ], array_column( $this->get_changes( [ 'type' => 'plugin' ] )->get_data(), 'item' ) );
		$this->assertSame( [ 'jetpack' ], array_column( $this->get_changes( [ 'source' => 'deploy' ] )->get_data(), 'item' ) );
		$this->assertSame( [ 'bob' ], array_column( $this->get_changes( [ 'action' => 'role' ] )->get_data(), 'item' ) );
		$this->assertSame(
			[ 'jetpack' ],
			array_column(
				$this->get_changes(
					[
						'since' => '2026-11-02T00:00:00Z',
						'until' => '2026-11-06T00:00:00Z',
					]
				)->get_data(),
				'item'
			)
		);
		$this->assertSame( 400, $this->get_changes( [ 'source' => 'nope' ] )->get_status() );
	}

	public function test_pagination(): void {
		$this->seed();

		$response = $this->get_changes(
			[
				'per_page' => 2,
				'page'     => 2,
			]
		);

		$this->assertSame( [ 'akismet' ], array_column( $response->get_data(), 'item' ) );
		$this->assertSame( '3', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '2', $response->get_headers()['X-WP-TotalPages'] );
	}

	public function test_permissions(): void {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->get_changes()->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 403, $this->get_changes()->get_status() );
	}
}
