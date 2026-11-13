<?php
/**
 * Configurable check for runner/scheduler tests.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Support;

use AMC\Checks\Check;
use AMC\Checks\Context;
use AMC\Checks\Group;
use AMC\Checks\Result;
use AMC\Checks\Schedule;

/**
 * Class FakeCheck
 *
 * $result may be a Result, a callable( Context ): Result, or a Throwable to throw.
 */
final class FakeCheck implements Check {

	/**
	 * Contexts passed to run().
	 *
	 * @var list<Context>
	 */
	public array $contexts = [];

	/**
	 * Constructor.
	 *
	 * @param string                     $id       Check id.
	 * @param Result|callable|\Throwable $result   Outcome.
	 * @param Schedule                   $schedule Schedule.
	 * @param Group                      $group    Group.
	 */
	public function __construct(
		private string $id,
		private mixed $result,
		private Schedule $schedule = Schedule::HOURLY,
		private Group $group = Group::HEALTH
	) {}

	public function id(): string {
		return $this->id;
	}

	public function group(): Group {
		return $this->group;
	}

	public function label(): string {
		return 'Fake ' . $this->id;
	}

	public function schedule(): Schedule {
		return $this->schedule;
	}

	public function run( Context $ctx ): Result {
		$this->contexts[] = $ctx;

		if ( $this->result instanceof \Throwable ) {
			throw $this->result;
		}

		return is_callable( $this->result ) ? ( $this->result )( $ctx ) : $this->result;
	}

	/**
	 * Change the outcome of the next run.
	 *
	 * @param Result|callable|\Throwable $result Outcome.
	 */
	public function will_return( mixed $result ): void {
		$this->result = $result;
	}
}
