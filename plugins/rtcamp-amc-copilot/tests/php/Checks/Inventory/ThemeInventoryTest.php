<?php
/**
 * Tests for the inventory.themes check.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Checks\Inventory;

use AMC\Checks\Context;
use AMC\Checks\Group;
use AMC\Checks\Inventory\ThemeInventory;
use AMC\Checks\Schedule;
use AMC\Checks\Status;
use AMC\Storage\SnapshotsRepository;
use AMC\Tests\TestCase;

/**
 * Class ThemeInventoryTest
 */
final class ThemeInventoryTest extends TestCase {

	/**
	 * Build the check around a fixture theme list.
	 *
	 * @param string $fixture    Fixture name in fixtures/inventory-themes/.
	 * @param string $stylesheet Active stylesheet.
	 */
	private function check_with( string $fixture, string $stylesheet ): ThemeInventory {
		return new ThemeInventory(
			fn (): array => $this->fixture( 'inventory-themes/' . $fixture ),
			static fn (): array => [
				'stylesheet' => $stylesheet,
				'template'   => $stylesheet,
			]
		);
	}

	public function test_metadata(): void {
		$check = new ThemeInventory();

		$this->assertSame( 'inventory.themes', $check->id() );
		$this->assertSame( Group::INVENTORY, $check->group() );
		$this->assertSame( Schedule::WEEKLY, $check->schedule() );
		$this->assertNotSame( '', $check->label() );
	}

	public function test_themes_are_listed_and_the_active_one_is_flagged(): void {
		$result = $this->check_with( 'themes', 'twentytwentysix' )->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( [], $result->findings );
		$this->assertSame( [ 'themes', 'total', 'active' ], array_keys( $result->data ) );
		$this->assertSame( 2, $result->data['total'] );
		$this->assertSame( 'twentytwentysix', $result->data['active'] );
		$this->assertSame( '2 themes installed.', $result->summary );

		$rows = [];
		foreach ( $result->data['themes'] as $row ) {
			$rows[ $row['stylesheet'] ] = $row;
		}

		$this->assertSame(
			[
				'stylesheet' => 'twentytwentysix',
				'name'       => 'Twenty Twenty-Six',
				'version'    => '1.0.2',
				'active'     => true,
				'parent'     => null,
			],
			$rows['twentytwentysix']
		);
		$this->assertSame(
			[
				'stylesheet' => 'storefront',
				'name'       => 'Storefront',
				'version'    => '4.6.0',
				'active'     => false,
				'parent'     => null,
			],
			$rows['storefront']
		);

		$snapshot = ( new SnapshotsRepository() )->latest( 'themes' );
		$this->assertNotNull( $snapshot );
		$this->assertCount( 2, $snapshot['data']['themes'] );
	}

	public function test_a_child_theme_reports_its_parent_stylesheet(): void {
		$result = $this->check_with( 'child', 'child' )->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( 'child', $result->data['active'] );

		$rows = [];
		foreach ( $result->data['themes'] as $row ) {
			$rows[ $row['stylesheet'] ] = $row;
		}

		$this->assertSame( 'Fixture Child', $rows['child']['name'] );
		$this->assertTrue( $rows['child']['active'] );
		$this->assertSame( 'parent', $rows['child']['parent'] );

		$this->assertSame( 'Fixture Parent', $rows['parent']['name'] );
		$this->assertFalse( $rows['parent']['active'] );
		$this->assertNull( $rows['parent']['parent'] );
	}

	public function test_no_themes_installed_passes_with_a_null_active_theme(): void {
		$check = new ThemeInventory(
			static fn (): array => [],
			static fn (): array => [
				'stylesheet' => '',
				'template'   => '',
			]
		);

		$result = $check->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( 0, $result->data['total'] );
		$this->assertNull( $result->data['active'] );
		$this->assertSame( '0 themes installed.', $result->summary );
	}
}
