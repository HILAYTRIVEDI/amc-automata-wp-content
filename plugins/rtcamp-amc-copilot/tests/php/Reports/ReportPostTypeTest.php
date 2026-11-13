<?php
/**
 * Tests for the amc_report post type.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Reports;

use AMC\Core\Capabilities;
use AMC\Reports\ReportPostType;
use AMC\Tests\TestCase;

/**
 * Class ReportPostTypeTest
 */
final class ReportPostTypeTest extends TestCase {

	public function test_registered_private_and_in_rest(): void {
		$type = get_post_type_object( ReportPostType::SLUG );

		$this->assertNotNull( $type );
		$this->assertFalse( $type->public );
		$this->assertFalse( $type->publicly_queryable );
		$this->assertTrue( $type->show_in_rest );
		$this->assertSame( 'edit_amc_reports', $type->cap->edit_posts );
		$this->assertTrue( post_type_supports( ReportPostType::SLUG, 'revisions' ) );
		$this->assertSame( 'amc/section-overview', $type->template[0][0] );
	}

	public function test_meta_is_registered(): void {
		// WP_UnitTestCase::tear_down() unregisters every meta key after each test.
		( new ReportPostType() )->after_register();

		$meta = get_registered_meta_keys( 'post', ReportPostType::SLUG );

		foreach ( array_keys( ReportPostType::META ) as $key ) {
			$this->assertArrayHasKey( $key, $meta );
		}
	}

	public function test_engineers_can_edit_and_publish_others_cannot(): void {
		$engineer = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$admin    = self::factory()->user->create( [ 'role' => 'administrator' ] );
		Capabilities::grant( $engineer );

		$this->assertTrue( user_can( $engineer, 'edit_amc_reports' ) );
		$this->assertTrue( user_can( $engineer, 'publish_amc_reports' ) );
		$this->assertFalse( user_can( $admin, 'edit_amc_reports' ), 'Client administrators cannot edit reports' );
	}
}
