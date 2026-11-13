<?php
/**
 * Tests for the real WordPress reads behind the snapshot.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Reports;

use AMC\Reports\Generator;
use AMC\Reports\SiteFacts;
use AMC\Reports\SnapshotSchema;

/**
 * Class SiteFactsTest
 */
final class SiteFactsTest extends ReportsTestCase {

	/**
	 * {@inheritDoc}
	 */
	public function set_up(): void {
		parent::set_up();

		// wordpress.org must never be called from a unit test.
		add_filter( 'pre_http_request', static fn (): \WP_Error => new \WP_Error( 'blocked', 'No network in tests.' ) );
	}

	public function test_plugins_report_name_version_and_active_state(): void {
		$plugins = array_column( ( new SiteFacts() )->plugins(), null, 'slug' );

		$this->assertArrayHasKey( 'rtcamp-amc-copilot', $plugins );
		$this->assertTrue( $plugins['rtcamp-amc-copilot']['active'] );
		$this->assertNotSame( '', $plugins['rtcamp-amc-copilot']['version'] );
		$this->assertSame( 'rtcamp-amc-copilot/rtcamp-amc-copilot.php', $plugins['rtcamp-amc-copilot']['file'] );
	}

	public function test_users_roles_and_administrators(): void {
		$facts = new SiteFacts();
		$users = $facts->user_counts();

		$this->assertGreaterThanOrEqual( 2, $users['total'], 'the engineer and the client administrator' );
		$this->assertContains( 'administrator', array_column( $users['roles'], 'role' ) );
		$this->assertContains( get_userdata( $this->client )->user_login, array_column( $facts->administrators(), 'login' ) );
	}

	public function test_users_registered_inside_a_window(): void {
		$facts = new SiteFacts();
		$user  = self::factory()->user->create(
			[
				'role'            => 'author',
				'user_registered' => '2026-10-10 10:00:00',
			]
		);
		$login = get_userdata( $user )->user_login;

		$inside  = array_column( $facts->users_registered( '2026-10-01 00:00:00', '2026-11-01 00:00:00' ), 'role', 'login' );
		$outside = array_column( $facts->users_registered( '2026-11-01 00:00:00', '2026-12-01 00:00:00' ), 'role', 'login' );

		$this->assertSame( 'author', $inside[ $login ] );
		$this->assertArrayNotHasKey( $login, $outside );
	}

	public function test_server_and_site_facts_have_the_right_types(): void {
		$facts = new SiteFacts();

		$this->assertSame( PHP_VERSION, $facts->php_version() );
		$this->assertIsString( $facts->mysql_version() );
		$this->assertContains( $facts->editor_type(), [ 'block', 'classic', 'mixed' ] );
		$this->assertIsInt( $facts->autoload_size_bytes() );
		$this->assertGreaterThan( 0, $facts->autoload_size_bytes() );
		$this->assertNotSame( '', $facts->wp_version() );
		$this->assertSame( get_current_blog_id(), $facts->blog_id() );
		$this->assertNull( $facts->host(), 'no hosting constant defined in tests' );
		$this->assertNull( $facts->php_recommendation(), 'wordpress.org unreachable: no recommendation, not an error' );
		$this->assertIsString( $facts->object_cache() );
		$this->assertArrayHasKey( 'active', $facts->active_theme() );
		$this->assertGreaterThanOrEqual( 1, $facts->themes_installed() );
	}

	public function test_default_author_is_the_lowest_engineer_id(): void {
		$other = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		\AMC\Core\Capabilities::grant( $other );

		$this->assertSame( min( $this->engineer, $other ), ( new SiteFacts() )->default_author() );
	}

	public function test_a_report_can_be_generated_from_the_real_site(): void {
		$generator = new Generator();
		$period    = $generator->resolve_period( 'custom', '2026-10-01', '2026-10-31' );

		$this->assertInstanceOf( \AMC\Reports\Period::class, $period );

		$post_id = $generator->generate( $period, $this->engineer );

		$this->assertIsInt( $post_id );

		$snapshot = SnapshotSchema::for_post( $post_id );

		$this->assertSame( home_url(), $snapshot['sections']['overview']['website_url'] );
		$this->assertSame( PHP_VERSION, $snapshot['sections']['hosting']['php_version'] );
		$this->assertGreaterThanOrEqual( 1, $snapshot['sections']['plugins_themes']['counts']['installed'] );
		$this->assertSame( get_userdata( $this->engineer )->display_name, $snapshot['sections']['overview']['developer_name'] );
	}
}
