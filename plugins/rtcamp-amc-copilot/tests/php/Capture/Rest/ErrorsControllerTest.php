<?php
/**
 * Tests for GET /errors and POST /errors/{signature}/status.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Capture\Rest;

use AMC\Checks\Finding;
use AMC\Checks\Severity;
use AMC\Core\Capabilities;
use AMC\Storage\EventsRepository;
use AMC\Storage\FindingsRepository;
use AMC\Tests\TestCase;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Class ErrorsControllerTest
 */
final class ErrorsControllerTest extends TestCase {

	/**
	 * Signature used in the tests.
	 */
	private const SIG = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$engineer = self::factory()->user->create( [ 'role' => 'administrator' ] );
		Capabilities::grant( $engineer );
		wp_set_current_user( $engineer );
	}

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
	private function request( string $method, string $path, array $params = [] ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, $path );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Seed events for SIG: 4 now, 3 two days ago; plus a notice.
	 */
	private function seed_events(): void {
		( new EventsRepository() )->upsert_many(
			[
				[
					'type'      => 'warning',
					'signature' => self::SIG,
					'message'   => 'Undefined array key N',
					'source'    => 'plugin:ctl',
					'file'      => 'wp-content/plugins/ctl/ctl.php',
					'line'      => 12,
					'hour'      => EventsRepository::hour(),
					'count'     => 4,
				],
				[
					'type'      => 'warning',
					'signature' => self::SIG,
					'message'   => 'Undefined array key N',
					'source'    => 'plugin:ctl',
					'file'      => 'wp-content/plugins/ctl/ctl.php',
					'line'      => 12,
					'hour'      => EventsRepository::hour( time() - 2 * DAY_IN_SECONDS ),
					'count'     => 3,
				],
				[
					'type'      => 'notice',
					'signature' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
					'message'   => 'A notice',
					'source'    => 'core',
					'file'      => 'wp-includes/x.php',
					'line'      => 1,
					'count'     => 1,
				],
			]
		);
	}

	/**
	 * The list item for SIG.
	 *
	 * @param \WP_REST_Response $response Response.
	 *
	 * @return array<string, mixed>|null
	 */
	private function item( \WP_REST_Response $response ): ?array {
		foreach ( $response->get_data() as $item ) {
			if ( self::SIG === $item['signature'] ) {
				return $item;
			}
		}

		return null;
	}

	public function test_list_shape_and_thirty_day_sparkline(): void {
		$this->seed_events();

		$response = $this->request( 'GET', '/amc/v1/errors', [ 'days' => 30 ] );
		$item     = $this->item( $response );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotNull( $item );
		$this->assertSame(
			[ 'signature', 'type', 'message', 'source', 'file', 'line', 'count', 'first_seen', 'last_seen', 'sparkline', 'finding_id', 'finding_status' ],
			array_keys( $item )
		);
		$this->assertSame( [ 'warning', 'plugin:ctl', 12, 7 ], [ $item['type'], $item['source'], $item['line'], $item['count'] ] );
		$this->assertCount( 30, $item['sparkline'] );
		$this->assertSame( 4, $item['sparkline'][29], 'Today is last' );
		$this->assertSame( 3, $item['sparkline'][27], 'Two days ago' );
		$this->assertSame( 0, $item['sparkline'][28] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $item['last_seen'] );
		$this->assertNull( $item['finding_id'] );
		$this->assertNull( $item['finding_status'] );
	}

	public function test_days_sets_the_sparkline_length(): void {
		$this->seed_events();

		$item = $this->item( $this->request( 'GET', '/amc/v1/errors', [ 'days' => 7 ] ) );

		$this->assertCount( 7, $item['sparkline'] );
		$this->assertSame( 4, $item['sparkline'][6] );
	}

	public function test_type_filter(): void {
		$this->seed_events();

		$this->assertNull( $this->item( $this->request( 'GET', '/amc/v1/errors', [ 'type' => 'notice' ] ) ) );
		$this->assertNotNull( $this->item( $this->request( 'GET', '/amc/v1/errors', [ 'type' => 'warning' ] ) ) );
		$this->assertSame( 400, $this->request( 'GET', '/amc/v1/errors', [ 'type' => 'nonsense' ] )->get_status() );
	}

	public function test_list_carries_the_matching_unresolved_finding(): void {
		$this->seed_events();
		$findings = new FindingsRepository();
		$id       = $findings->insert_finding( new Finding( self::SIG, Severity::MEDIUM, 'Warning' ), 'errors.warnings', 1 );

		$item = $this->item( $this->request( 'GET', '/amc/v1/errors' ) );

		$this->assertSame( $id, $item['finding_id'] );
		$this->assertSame( 'open', $item['finding_status'] );
	}

	public function test_mute_and_resolve_a_signature(): void {
		$this->seed_events();
		$findings = new FindingsRepository();
		$id       = $findings->insert_finding( new Finding( self::SIG, Severity::MEDIUM, 'Warning' ), 'errors.warnings', 1 );

		$muted = $this->request( 'POST', '/amc/v1/errors/' . self::SIG . '/status', [ 'status' => 'muted' ] );
		$this->assertSame( 200, $muted->get_status() );
		$this->assertSame( [ $id, 'muted' ], [ $muted->get_data()['id'], $muted->get_data()['status'] ] );
		$this->assertSame( 'muted', $this->item( $this->request( 'GET', '/amc/v1/errors' ) )['finding_status'], 'A muted finding is still unresolved and listed' );

		$open = $this->request( 'POST', '/amc/v1/errors/' . self::SIG . '/status', [ 'status' => 'open' ] );
		$this->assertSame( 'open', $open->get_data()['status'] );

		$resolved = $this->request( 'POST', '/amc/v1/errors/' . self::SIG . '/status', [ 'status' => 'resolved' ] );
		$this->assertSame( 'resolved', $resolved->get_data()['status'] );
		$this->assertNotNull( $resolved->get_data()['resolved_at'] );
		$this->assertNull( $this->item( $this->request( 'GET', '/amc/v1/errors' ) )['finding_id'], 'Resolved findings are not attached' );
	}

	public function test_status_without_a_finding_is_404(): void {
		$response = $this->request( 'POST', '/amc/v1/errors/' . self::SIG . '/status', [ 'status' => 'muted' ] );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'amc_not_found', $response->get_data()['code'] );
	}

	public function test_status_only_touches_errors_findings(): void {
		( new FindingsRepository() )->insert_finding( new Finding( self::SIG, Severity::LOW, 'Other check' ), 'updates.core', 1 );

		$this->assertSame( 404, $this->request( 'POST', '/amc/v1/errors/' . self::SIG . '/status', [ 'status' => 'muted' ] )->get_status() );
	}

	public function test_status_and_signature_are_validated(): void {
		$this->assertSame( 400, $this->request( 'POST', '/amc/v1/errors/' . self::SIG . '/status', [ 'status' => 'accepted' ] )->get_status() );
		$this->assertSame( 404, $this->request( 'POST', '/amc/v1/errors/not-a-signature/status', [ 'status' => 'muted' ] )->get_status() );
	}

	public function test_permissions(): void {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( 'GET', '/amc/v1/errors' )->get_status() );
		$this->assertSame( 401, $this->request( 'POST', '/amc/v1/errors/' . self::SIG . '/status', [ 'status' => 'muted' ] )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 403, $this->request( 'GET', '/amc/v1/errors' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/amc/v1/errors/' . self::SIG . '/status', [ 'status' => 'muted' ] )->get_status() );
	}
}
