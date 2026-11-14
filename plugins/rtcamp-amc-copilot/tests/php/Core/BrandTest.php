<?php
/**
 * Tests for Brand.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Core;

use AMC\Core\Brand;
use WP_UnitTestCase;

/**
 * Class BrandTest
 */
final class BrandTest extends WP_UnitTestCase {

	/**
	 * {@inheritDoc}
	 */
	public function tear_down(): void {
		wp_dequeue_style( Brand::FONTS_HANDLE );
		wp_deregister_style( Brand::FONTS_HANDLE );

		parent::tear_down();
	}

	public function test_font_face_points_at_the_bundled_files(): void {
		$css = Brand::font_face_css();

		$this->assertStringContainsString( 'font-family:"Reddit Sans"', $css );
		$this->assertStringContainsString( 'font-family:"Reddit Mono"', $css );
		$this->assertStringContainsString( 'assets/fonts/reddit-sans-latin.woff2', $css );
		$this->assertStringContainsString( 'assets/fonts/reddit-mono-latin.woff2', $css );
		$this->assertStringNotContainsString( 'fonts.googleapis', $css );
	}

	public function test_bundled_font_files_exist(): void {
		$this->assertFileExists( AMC_COPILOT_PATH . 'assets/fonts/reddit-sans-latin.woff2' );
		$this->assertFileExists( AMC_COPILOT_PATH . 'assets/fonts/reddit-mono-latin.woff2' );
	}

	public function test_enqueue_fonts_is_idempotent(): void {
		Brand::enqueue_fonts();
		Brand::enqueue_fonts();

		$this->assertTrue( wp_style_is( Brand::FONTS_HANDLE, 'enqueued' ) );

		$inline = wp_styles()->get_data( Brand::FONTS_HANDLE, 'after' );
		$this->assertIsArray( $inline );
		$this->assertCount( 1, $inline );
	}
}
