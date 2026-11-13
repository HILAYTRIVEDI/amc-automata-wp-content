<?php
/**
 * Tests for the Roles module and its example custom role.
 *
 * Runs under real WordPress (wp-env): maybe_update_role() actually registers
 * the role and writes its version option, so assertions go through get_role()
 * and get_option(). Roles live in an in-memory singleton that is not rolled
 * back per test, so the role is removed in tear_down().
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Tests;

use AMC_Automata_Features\Features\Modules\Roles;
use AMC_Automata_Features\Features\Modules\Roles\ExampleUserRole;
use ReflectionMethod;

/**
 * Class RolesTest
 */
final class RolesTest extends TestCase {

	/**
	 * Slug the example role registers under.
	 */
	private const SLUG = 'amc_automata_features_content_editor';

	/**
	 * Option key that stores the registered role version.
	 */
	private const VERSION_KEY = 'amc_automata_features_content_editor_role_version';

	/**
	 * maybe_update_role() runs on admin_init and the role lives in an in-memory
	 * singleton, so set up an administrator context.
	 */
	public function set_up(): void {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$GLOBALS['menu']             = [];
		$GLOBALS['submenu']          = [];
		$GLOBALS['admin_page_hooks'] = [];
	}

	/**
	 * Remove the role and its version option so they do not leak.
	 */
	public function tear_down(): void {
		remove_role( self::SLUG );
		delete_option( self::VERSION_KEY );

		parent::tear_down();
	}

	/**
	 * Read the module's protected get_classes() list.
	 *
	 * @return array<int, class-string>
	 */
	private function module_classes(): array {
		$method = new ReflectionMethod( Roles::class, 'get_classes' );
		$method->setAccessible( true );

		return $method->invoke( new Roles() );
	}

	/**
	 * The example role is listed in the module's class list.
	 */
	public function test_example_is_listed_in_get_classes(): void {
		$this->assertContains( ExampleUserRole::class, $this->module_classes() );
	}

	/**
	 * The example is the expected concrete AbstractUserRole subtype.
	 */
	public function test_example_is_user_role_instance(): void {
		$this->assertInstanceOf(
			'rtCamp\WPPrimitives\Contracts\Abstracts\AbstractUserRole',
			new ExampleUserRole()
		);
	}

	/**
	 * The static get_slug() returns the documented slug without an instance.
	 */
	public function test_static_get_slug_is_accessible(): void {
		$this->assertSame( self::SLUG, ExampleUserRole::get_slug() );
	}

	/**
	 * register_hooks() wires role registration onto admin_init.
	 */
	public function test_register_hooks_wires_admin_init(): void {
		$role = new ExampleUserRole();
		$role->register_hooks();

		$this->assertNotFalse( has_action( 'admin_init', [ $role, 'maybe_update_role' ] ) );
	}

	/**
	 * maybe_update_role() registers the role with its capabilities and stores
	 * the version.
	 */
	public function test_maybe_update_role_registers_role_and_stores_version(): void {
		( new ExampleUserRole() )->maybe_update_role();

		$role = get_role( self::SLUG );
		$this->assertNotNull( $role );
		$this->assertTrue( $role->has_cap( 'read' ) );
		$this->assertTrue( $role->has_cap( 'edit_posts' ) );
		$this->assertTrue( $role->has_cap( 'publish_posts' ) );
		$this->assertTrue( $role->has_cap( 'upload_files' ) );
		$this->assertFalse( $role->has_cap( 'delete_posts' ) );
		$this->assertFalse( $role->has_cap( 'delete_published_posts' ) );

		$this->assertSame( 1, (int) get_option( self::VERSION_KEY ) );
	}

	/**
	 * Re-running maybe_update_role() when the stored version is current is a
	 * no-op: the role remains registered and the version is unchanged.
	 */
	public function test_maybe_update_role_is_idempotent(): void {
		$role = new ExampleUserRole();
		$role->maybe_update_role();
		$role->maybe_update_role();

		$this->assertNotNull( get_role( self::SLUG ) );
		$this->assertSame( 1, (int) get_option( self::VERSION_KEY ) );
	}

	/**
	 * remove_role() removes the role and deletes the version option.
	 */
	public function test_remove_role_deletes_role_and_version_option(): void {
		$role = new ExampleUserRole();
		$role->maybe_update_role();
		$this->assertNotNull( get_role( self::SLUG ) );

		$role->remove_role();

		$this->assertNull( get_role( self::SLUG ) );
		$this->assertFalse( get_option( self::VERSION_KEY ) );
	}
}
