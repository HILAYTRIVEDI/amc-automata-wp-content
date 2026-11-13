<?php
/**
 * Test.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Reports\Blocks;

use AMC\Reports\Blocks\CustomSectionBlock;
use AMC\Tests\TestCase;

/**
 * Test.
 *
 * @package AMC
 */
final class CustomSectionBlockTest extends TestCase {

	public function test_block_is_registered(): void {
		$type = \WP_Block_Type_Registry::get_instance()->get_registered( CustomSectionBlock::get_name() );

		$this->assertSame( 'amc/custom-section', CustomSectionBlock::get_name() );
		$this->assertNotNull( $type );
	}

	public function test_renders_the_saved_content(): void {
		$markup = '<!-- wp:amc/custom-section {"title":"Notes"} --><section class="amc-section amc-section--custom"><h2 class="wp-block-heading">Notes</h2><!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph --></section><!-- /wp:amc/custom-section -->';

		$html = do_blocks( $markup );

		$this->assertStringContainsString( 'amc-section--custom', $html );
		$this->assertStringContainsString( 'Hello</p>', $html );
	}
}
