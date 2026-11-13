<?php
/**
 * Tests for the inventory.mu_dropins check.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Checks\Inventory;

use AMC\Checks\Context;
use AMC\Checks\Group;
use AMC\Checks\Inventory\MuDropins;
use AMC\Checks\Schedule;
use AMC\Checks\Status;
use AMC\Tests\TestCase;

/**
 * Class MuDropinsTest
 */
final class MuDropinsTest extends TestCase {

	/**
	 * Build the check around fixture must-use plugins and drop-ins.
	 *
	 * @param array<string, mixed> $mu      Must-use plugins.
	 * @param array<string, mixed> $dropins Drop-ins.
	 */
	private function check_with( array $mu, array $dropins ): MuDropins {
		return new MuDropins( static fn (): array => $mu, static fn (): array => $dropins );
	}

	public function test_metadata(): void {
		$check = new MuDropins();

		$this->assertSame( 'inventory.mu_dropins', $check->id() );
		$this->assertSame( Group::INVENTORY, $check->group() );
		$this->assertSame( Schedule::WEEKLY, $check->schedule() );
		$this->assertNotSame( '', $check->label() );
	}

	public function test_no_mu_plugins_or_dropins_passes_with_zero_totals(): void {
		$result = $this->check_with( [], [] )->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( [], $result->findings );
		$this->assertSame( [ 'mu_plugins', 'dropins', 'total' ], array_keys( $result->data ) );
		$this->assertSame( [], $result->data['mu_plugins'] );
		$this->assertSame( [], $result->data['dropins'] );
		$this->assertSame( 0, $result->data['total'] );
		$this->assertSame( '0 must-use plugins and 0 drop-ins found.', $result->summary );
	}

	public function test_mu_plugins_and_dropins_are_normalised(): void {
		$result = $this->check_with(
			$this->fixture( 'inventory-mu-dropins/mu' ),
			$this->fixture( 'inventory-mu-dropins/dropins' )
		)->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( [], $result->findings );
		$this->assertSame( 5, $result->data['total'] );
		$this->assertSame( '2 must-use plugins and 3 drop-ins found.', $result->summary );

		$this->assertSame(
			[
				[
					'file'        => 'amc-maintenance/amc-maintenance.php',
					'name'        => 'AMC Maintenance',
					'version'     => '1.2.0',
					'description' => 'Local maintenance helpers.',
				],
				[
					'file'        => 'mu-loader.php',
					'name'        => 'mu-loader.php',
					'version'     => '0.1.0',
					'description' => '',
				],
			],
			$result->data['mu_plugins']
		);

		$this->assertSame(
			[
				[
					'file'        => 'advanced-cache.php',
					'description' => 'Advanced caching drop-in.',
					'active'      => true,
				],
				[
					'file'        => 'object-cache.php',
					'description' => 'Object cache drop-in.',
					'active'      => true,
				],
				[
					'file'        => 'maintenance.php',
					'description' => 'Maintenance mode drop-in.',
					'active'      => false,
				],
			],
			$result->data['dropins']
		);
	}
}
