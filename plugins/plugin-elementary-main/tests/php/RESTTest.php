<?php
/**
 * Tests for the REST module and its example controller.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Tests;

use AMC_Automata_Features\Features\Modules\REST;
use AMC_Automata_Features\Features\Modules\REST\ExampleRESTController;
use ReflectionMethod;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class RESTTest
 */
final class RESTTest extends TestCase {

	/**
	 * Fully-qualified route registered by the example controller.
	 */
	private const ROUTE = '/amc-automata-features-features/v1/examples';

	/**
	 * {@inheritDoc}
	 *
	 * Force a fresh REST server so rest_api_init fires again for each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$GLOBALS['wp_rest_server'] = null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Drop the booted server so the next test starts clean.
	 */
	public function tear_down(): void {
		$GLOBALS['wp_rest_server'] = null;

		parent::tear_down();
	}

	/**
	 * The example controller is one of the module's managed classes.
	 */
	public function test_controller_is_in_module_get_classes(): void {
		$method = new ReflectionMethod( REST::class, 'get_classes' );
		$method->setAccessible( true );

		$this->assertContains( ExampleRESTController::class, $method->invoke( new REST() ) );
	}

	/**
	 * register_hooks() wires route registration onto rest_api_init.
	 */
	public function test_register_hooks_registers_routes_on_rest_api_init(): void {
		$controller = new ExampleRESTController();
		$controller->register_hooks();

		$this->assertNotFalse( has_action( 'rest_api_init', [ $controller, 'register_routes' ] ) );
	}

	/**
	 * After rest_api_init fires the route is present in the server's route table.
	 */
	public function test_route_is_registered_with_the_rest_server(): void {
		( new ExampleRESTController() )->register_hooks();

		// rest_get_server() boots the server and fires rest_api_init.
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( self::ROUTE, $routes );
	}

	/**
	 * A request to the route returns a 200 WP_REST_Response with the example shape.
	 */
	public function test_route_responds_with_expected_status_and_shape(): void {
		// An administrator passes the manage_options permission check.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		( new ExampleRESTController() )->register_hooks();

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', self::ROUTE ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'example_response' => 'example_response' ], $response->get_data() );
	}

	/**
	 * Without manage_options the endpoint is forbidden.
	 */
	public function test_route_is_forbidden_without_capability(): void {
		wp_set_current_user( 0 );

		( new ExampleRESTController() )->register_hooks();

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', self::ROUTE ) );

		$this->assertSame( rest_authorization_required_code(), $response->get_status() );
	}
}
