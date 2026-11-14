<?php
/**
 * Tests for the inventory.editor check.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Checks\Inventory;

use AMC\Checks\Context;
use AMC\Checks\Group;
use AMC\Checks\Inventory\Editor;
use AMC\Checks\Schedule;
use AMC\Checks\Status;
use AMC\Tests\TestCase;

/**
 * Class EditorTest
 */
final class EditorTest extends TestCase {

	/**
	 * Build the check around a fixture editor state.
	 *
	 * @param string       $editors           Fixture name in fixtures/inventory-editor/.
	 * @param list<string> $active            Active plugin files.
	 * @param bool         $classic_installed Whether the Classic Editor plugin is installed.
	 */
	private function check_with( string $editors, array $active = [], bool $classic_installed = false ): Editor {
		return new Editor(
			fn (): array => $classic_installed ? $this->fixture( 'inventory-editor/plugins' ) : [],
			static fn (): array => $active,
			fn (): array => $this->fixture( 'inventory-editor/' . $editors )
		);
	}

	public function test_metadata(): void {
		$check = new Editor();

		$this->assertSame( 'inventory.editor', $check->id() );
		$this->assertSame( Group::INVENTORY, $check->group() );
		$this->assertSame( Schedule::WEEKLY, $check->schedule() );
		$this->assertNotSame( '', $check->label() );
	}

	public function test_block_editor_everywhere_passes_as_block(): void {
		$result = $this->check_with( 'editors' )->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( [], $result->findings );
		$this->assertSame( [ 'classic_editor', 'classic_editor_active', 'post_types', 'editor' ], array_keys( $result->data ) );
		$this->assertFalse( $result->data['classic_editor'] );
		$this->assertFalse( $result->data['classic_editor_active'] );
		$this->assertSame(
			[
				'post' => true,
				'page' => true,
			],
			$result->data['post_types']
		);
		$this->assertSame( 'block', $result->data['editor'] );
		$this->assertSame( 'Editor type: block.', $result->summary );
	}

	public function test_an_inactive_classic_editor_plugin_is_reported_but_does_not_change_the_type(): void {
		$result = $this->check_with( 'editors', [], true )->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertTrue( $result->data['classic_editor'] );
		$this->assertFalse( $result->data['classic_editor_active'] );
		$this->assertSame( 'block', $result->data['editor'] );
	}

	public function test_an_active_classic_editor_plugin_makes_the_editor_classic(): void {
		$result = $this->check_with( 'editors', [ 'classic-editor/classic-editor.php' ], true )
			->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertTrue( $result->data['classic_editor'] );
		$this->assertTrue( $result->data['classic_editor_active'] );
		$this->assertSame( 'classic', $result->data['editor'] );
	}

	public function test_every_post_type_on_the_classic_editor_is_classic_without_the_plugin(): void {
		$result = $this->check_with( 'editors-classic' )->run( Context::for_tests() );

		$this->assertSame( 'classic', $result->data['editor'] );
		$this->assertFalse( $result->data['classic_editor'] );
	}

	public function test_a_mix_of_editors_is_reported_as_mixed(): void {
		$result = $this->check_with( 'editors-mixed' )->run( Context::for_tests() );

		$this->assertSame( 'mixed', $result->data['editor'] );
		$this->assertSame(
			[
				'post' => true,
				'page' => false,
			],
			$result->data['post_types']
		);
	}

	public function test_default_sources_read_the_public_post_types(): void {
		$result = ( new Editor() )->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertArrayHasKey( 'post', $result->data['post_types'] );
		$this->assertTrue( $result->data['post_types']['post'] );
		$this->assertFalse( $result->data['classic_editor'] );
		$this->assertNotSame( 'classic', $result->data['editor'] );
	}
}
