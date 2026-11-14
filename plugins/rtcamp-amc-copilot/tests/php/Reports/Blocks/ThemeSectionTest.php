<?php
/**
 * Test.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Reports\Blocks;

use AMC\Reports\Blocks\ThemeSection;
use AMC\Reports\SnapshotSchema;
use AMC\Tests\TestCase;

/**
 * Test.
 *
 * @package AMC
 */
final class ThemeSectionTest extends TestCase {

	/**
	 * Render the block inside a report whose snapshot has this theme section.
	 *
	 * @param string               $block_markup Block markup.
	 * @param array<string, mixed> $section      Theme section values.
	 */
	private function render_in_report( string $block_markup, array $section = [] ): string {
		$snapshot                      = SnapshotSchema::empty_snapshot();
		$snapshot['sections']['theme'] = $section;
		$post_id                       = self::factory()->post->create(
			[
				'post_type'    => 'amc_report',
				'post_content' => $block_markup,
			]
		);
		update_post_meta( $post_id, SnapshotSchema::META_KEY, wp_slash( wp_json_encode( $snapshot ) ) );
		$this->go_to( '/' );
		$GLOBALS['post'] = get_post( $post_id );
		setup_postdata( $GLOBALS['post'] );
		return do_blocks( $block_markup );
	}

	public function test_block_is_registered(): void {
		$type = \WP_Block_Type_Registry::get_instance()->get_registered( ThemeSection::get_name() );
		$this->assertSame( 'amc/section-theme', ThemeSection::get_name() );
		$this->assertNotNull( $type );
	}

	public function test_hidden_section_renders_nothing(): void {
		$this->assertSame( '', trim( $this->render_in_report( '<!-- wp:amc/section-theme {"hidden":true} /-->' ) ) );
	}

	public function test_themes_render_as_name_and_version_not_json(): void {
		$html = $this->render_in_report(
			'<!-- wp:amc/section-theme /-->',
			[
				'active_theme' => [
					'name'    => 'Child Theme',
					'slug'    => 'child',
					'version' => '2.0',
				],
				'parent_theme' => [
					'name'    => '',
					'slug'    => 'twentytwentyfive',
					'version' => '1.5',
				],
			]
		);

		$this->assertStringContainsString( 'Child Theme 2.0', $html );
		$this->assertStringContainsString( 'twentytwentyfive 1.5', $html );
		$this->assertStringNotContainsString( '&quot;slug&quot;', $html );
		$this->assertStringNotContainsString( '{', $html );
	}
}
