<?php
/**
 * Tests for the Overview section block.
 *
 * ==== EXEMPLAR SECTION BLOCK TEST: copy this file for each section block. ====
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Reports\Blocks;

use AMC\Reports\Blocks\OverviewSection;
use AMC\Reports\SnapshotSchema;
use AMC\Tests\TestCase;

/**
 * Class OverviewSectionTest
 */
final class OverviewSectionTest extends TestCase {

	/**
	 * Create a report with an overview snapshot and render the given block markup.
	 *
	 * @param string $block_markup Serialized block.
	 *
	 * @return string Rendered HTML.
	 */
	private function render_in_report( string $block_markup ): string {
		$snapshot                         = SnapshotSchema::empty_snapshot();
		$snapshot['sections']['overview'] = [
			'website_url'          => 'https://client.test',
			'developer_name'       => 'Jane Engineer',
			'report_date'          => '2026-11-01',
			'client_name'          => 'Acme',
			'maintenance_schedule' => 'Monthly',
		];

		$post_id = self::factory()->post->create(
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

	public function test_block_is_registered_with_lock_and_hidden_attributes(): void {
		$type = \WP_Block_Type_Registry::get_instance()->get_registered( OverviewSection::get_name() );

		$this->assertSame( 'amc/section-overview', OverviewSection::get_name() );
		$this->assertNotNull( $type );
		$this->assertSame(
			[
				'move'   => true,
				'remove' => true,
			],
			$type->attributes['lock']['default']
		);
		$this->assertFalse( $type->attributes['hidden']['default'] );
		$this->assertContains( 'postId', $type->uses_context );
	}

	public function test_renders_snapshot_values_in_a_core_table(): void {
		$html = $this->render_in_report( '<!-- wp:amc/section-overview /-->' );

		$this->assertStringContainsString( '<h2 class="wp-block-heading">Overview</h2>', $html );
		$this->assertStringContainsString( 'class="wp-block-table"', $html );
		$this->assertStringContainsString( '<a href="https://client.test">', $html );
		$this->assertStringContainsString( 'Jane Engineer', $html );
		$this->assertStringContainsString( 'Acme', $html );
	}

	public function test_overrides_win_and_engineer_html_is_sanitised(): void {
		$html = $this->render_in_report( '<!-- wp:amc/section-overview {"overrides":{"client_name":"Acme Ltd"},"engineer":{"hours_used":"<strong>4</strong><script>x</script>"}} /-->' );

		$this->assertStringContainsString( 'Acme Ltd', $html );
		$this->assertStringContainsString( '<strong>4</strong>', $html );
		$this->assertStringNotContainsString( '<script>', $html );
	}

	public function test_hidden_section_renders_nothing(): void {
		$this->assertSame( '', trim( $this->render_in_report( '<!-- wp:amc/section-overview {"hidden":true} /-->' ) ) );
	}
}
