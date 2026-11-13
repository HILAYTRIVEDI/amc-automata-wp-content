<?php
/**
 * Shared helpers for the report tests.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Reports;

use AMC\Core\Capabilities;
use AMC\Reports\Generator;
use AMC\Reports\Period;
use AMC\Reports\Periods;
use AMC\Reports\SnapshotBuilder;
use AMC\Storage\ChangesRepository;
use AMC\Storage\RunsRepository;
use AMC\Storage\Tables;
use AMC\Tests\TestCase;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Class ReportsTestCase
 */
abstract class ReportsTestCase extends TestCase {

	/**
	 * Engineer user ID.
	 *
	 * @var int
	 */
	protected int $engineer = 0;

	/**
	 * Client administrator user ID (has amc_view_reports through the role).
	 *
	 * @var int
	 */
	protected int $client = 0;

	/**
	 * {@inheritDoc}
	 */
	public function set_up(): void {
		parent::set_up();

		delete_option( 'amc_saved_fields' );

		// The activation hook does not run in the test suite.
		get_role( 'administrator' )->add_cap( Capabilities::VIEW_REPORTS );

		$this->engineer = self::factory()->user->create(
			[
				'role'         => 'editor',
				'display_name' => 'Erin Engineer',
			]
		);
		Capabilities::grant( $this->engineer );

		$this->client = self::factory()->user->create( [ 'role' => 'administrator' ] );
	}

	/**
	 * October 2026 in UTC.
	 */
	protected function october(): Period {
		return Periods::previous( Period::TYPE_MONTH, new DateTimeImmutable( '2026-11-03 09:00:00', new DateTimeZone( 'UTC' ) ), null, new DateTimeZone( 'UTC' ) );
	}

	/**
	 * A fixed "now" just after October 2026.
	 */
	protected function clock(): callable {
		return static fn (): DateTimeImmutable => new DateTimeImmutable( '2026-11-01 06:00:00', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * A builder over the stub site facts with a fixed clock.
	 *
	 * @param StubSiteFacts|null $facts Facts.
	 */
	protected function builder( ?StubSiteFacts $facts = null ): SnapshotBuilder {
		return new SnapshotBuilder( $facts ?? new StubSiteFacts(), null, null, null, null, null, null, new DateTimeZone( 'UTC' ), $this->clock() );
	}

	/**
	 * A generator over the stub site facts with a fixed clock.
	 *
	 * @param StubSiteFacts|null $facts Facts.
	 */
	protected function generator( ?StubSiteFacts $facts = null ): Generator {
		$facts ??= new StubSiteFacts();

		return new Generator( $this->builder( $facts ), $facts, $this->clock() );
	}

	/**
	 * Store a finished run with data, at a given time.
	 *
	 * @param string               $check_id Check id.
	 * @param array<string, mixed> $data     Run data.
	 * @param string               $at       UTC 'Y-m-d H:i:s' (default: now).
	 * @param string               $status   Run status.
	 *
	 * @return int Run ID.
	 */
	protected function seed_run( string $check_id, array $data, string $at = '', string $status = 'pass' ): int {
		global $wpdb;

		$runs = new RunsRepository();
		$id   = $runs->create_running( $check_id, 'cron' );
		$runs->finish( $id, $status, 'seeded', $data, 5 );

		if ( '' !== $at ) {
			$wpdb->update( Tables::name( Tables::RUNS ), [ 'created_at' => $at ], [ 'id' => $id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test fixture.
		}

		return $id;
	}

	/**
	 * Add a change-log row.
	 *
	 * @param string               $type   plugin | theme | core | user.
	 * @param string               $action Action.
	 * @param string               $item   Slug or login.
	 * @param string               $at     UTC 'Y-m-d H:i:s'.
	 * @param array<string, mixed> $extra  Other columns.
	 */
	protected function seed_change( string $type, string $action, string $item, string $at, array $extra = [] ): void {
		( new ChangesRepository() )->insert(
			array_merge(
				[
					'type'       => $type,
					'action'     => $action,
					'item'       => $item,
					'created_at' => $at,
					'source'     => 'admin',
				],
				$extra
			)
		);
	}
}
