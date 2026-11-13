<?php
/**
 * Test.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Reports\Blocks;

use AMC\Reports\Blocks\DatabaseBackupsSection;
use AMC\Reports\SnapshotSchema;
use AMC\Tests\TestCase;

/**
 * Test.
 *
 * @package AMC
 */
final class DatabaseBackupsSectionTest extends TestCase {

	private function render_in_report( string $block_markup ): string {
		$snapshot                                 = SnapshotSchema::empty_snapshot();
		$snapshot['sections']['database_backups'] = [];
		$post_id                                  = self::factory()->post->create(
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
		$type = \WP_Block_Type_Registry::get_instance()->get_registered( DatabaseBackupsSection::get_name() );
		$this->assertSame( 'amc/section-database-backups', DatabaseBackupsSection::get_name() );
		$this->assertNotNull( $type );
	}

	public function test_hidden_section_renders_nothing(): void {
		$this->assertSame( '', trim( $this->render_in_report( '<!-- wp:amc/section-database-backups {"hidden":true} /-->' ) ) );
	}
}
