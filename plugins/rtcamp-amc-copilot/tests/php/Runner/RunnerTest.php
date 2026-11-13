<?php
/**
 * Tests for the Runner.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Runner;

use AMC\Checks\Context;
use AMC\Checks\Finding;
use AMC\Checks\Result;
use AMC\Checks\Severity;
use AMC\Core\Settings;
use AMC\Runner\Lock;
use AMC\Runner\ProcessedFindings;
use AMC\Runner\Runner;
use AMC\Runner\Scheduler;
use AMC\Storage\FindingsRepository;
use AMC\Storage\RunsRepository;
use AMC\Tests\Support\FakeCheck;
use AMC\Tests\TestCase;

/**
 * Class RunnerTest
 */
final class RunnerTest extends TestCase {

	/**
	 * Payloads of amc_findings_processed.
	 *
	 * @var list<ProcessedFindings>
	 */
	private array $processed = [];

	public function set_up(): void {
		parent::set_up();

		$this->processed = [];
		add_action(
			'amc_findings_processed',
			function ( ProcessedFindings $p ): void {
				$this->processed[] = $p;
			}
		);
	}

	public function test_run_stores_the_result_and_passes_context(): void {
		$check = new FakeCheck( 'test.store', Result::warn( 'Careful', [ 'x' => 1 ] ) );
		$this->register_checks( $check );

		$row = ( new Runner() )->run( 'test.store', 'cli', 3 );

		$this->assertIsArray( $row );
		$this->assertSame( 'warn', $row['status'] );
		$this->assertSame( 'Careful', $row['summary'] );
		$this->assertSame( [ 'x' => 1 ], $row['data'] );
		$this->assertSame( 'cli', $row['trigger_type'] );
		$this->assertSame( 3, $row['user_id'] );
		$this->assertNotNull( $row['finished_at'] );

		$ctx = $check->contexts[0];
		$this->assertInstanceOf( Context::class, $ctx );
		$this->assertSame( get_current_blog_id(), $ctx->blog_id );
		$this->assertSame( $row['id'], $ctx->run_id );
		$this->assertNull( $ctx->previous_data );
	}

	public function test_second_run_gets_previous_data(): void {
		$check = new FakeCheck( 'test.prev', Result::info( '', [ 'cursor' => 10 ] ) );
		$this->register_checks( $check );
		$runner = new Runner();

		$runner->run( 'test.prev' );
		$runner->run( 'test.prev' );

		$this->assertSame( [ 'cursor' => 10 ], $check->contexts[1]->previous_data );
	}

	public function test_findings_are_deduped_and_resolved_and_action_fires(): void {
		$a     = new Finding( 'a', Severity::HIGH, 'A' );
		$b     = new Finding( 'b', Severity::LOW, 'B' );
		$check = new FakeCheck( 'test.findings', Result::fail( '', [], [ $a, $b, $a ] ) );
		$this->register_checks( $check );
		$runner = new Runner();
		$repo   = new FindingsRepository();

		// Run 1: two new findings (the duplicate key is ignored).
		$runner->run( 'test.findings' );
		$this->assertCount( 2, $this->processed[0]->new );
		$this->assertSame( 2, $repo->query( [ 'check_id' => 'test.findings' ] )['total'] );

		// Run 2: 'a' seen again, 'b' gone.
		$check->will_return( Result::fail( '', [], [ $a ] ) );
		$runner->run( 'test.findings' );
		$this->assertCount( 0, $this->processed[1]->new );
		$this->assertCount( 1, $this->processed[1]->ongoing );
		$this->assertCount( 1, $this->processed[1]->resolved );
		$this->assertSame( 'b', $this->processed[1]->resolved[0]['dedupe_key'] );
		$this->assertSame( 'resolved', $this->processed[1]->resolved[0]['status'] );

		// Run 3: 'b' comes back -> a NEW row (so it alerts again).
		$check->will_return( Result::fail( '', [], [ $a, $b ] ) );
		$runner->run( 'test.findings' );
		$this->assertCount( 1, $this->processed[2]->new );
		$this->assertSame( 3, $repo->query( [ 'check_id' => 'test.findings' ] )['total'] );
		$this->assertSame( 'test.findings', $this->processed[2]->check_id );
	}

	public function test_a_throwing_check_is_stored_as_error_and_resolves_nothing(): void {
		$check = new FakeCheck( 'test.boom', Result::fail( '', [], [ new Finding( 'a', Severity::HIGH, 'A' ) ] ) );
		$this->register_checks( $check );
		$runner = new Runner();
		$runner->run( 'test.boom' );

		$check->will_return( new \RuntimeException( 'API down' ) );
		$row = $runner->run( 'test.boom' );

		$this->assertSame( 'error', $row['status'] );
		$this->assertStringContainsString( 'API down', $row['summary'] );
		$this->assertCount( 1, $this->processed, 'No findings processing after an error' );
		$this->assertCount( 1, ( new FindingsRepository() )->unresolved_for_check( 'test.boom' ) );
	}

	public function test_locked_check_is_refused(): void {
		$this->register_checks( new FakeCheck( 'test.locked', Result::pass( '' ) ) );
		$lock = Lock::for_check( 'test.locked', get_current_blog_id() );
		$lock->acquire();

		$result = ( new Runner() )->run( 'test.locked' );

		$this->assertWPError( $result );
		$this->assertSame( 'amc_locked', $result->get_error_code() );
		$lock->release();
	}

	public function test_kill_switch_stops_runs_and_enqueues(): void {
		$this->register_checks( new FakeCheck( 'test.off', Result::pass( '' ) ) );
		Settings::update( [ 'kill_switch' => true ] );

		$this->assertSame( 'amc_disabled', ( new Runner() )->run( 'test.off' )->get_error_code() );
		$this->assertSame( 'amc_disabled', ( new Runner() )->enqueue( 'test.off' )->get_error_code() );
	}

	public function test_unknown_check(): void {
		$this->assertSame( 'amc_unknown_check', ( new Runner() )->run( 'nope.nope' )->get_error_code() );
	}

	public function test_enqueue_never_runs_inline_and_reuses_pending_run(): void {
		$check = new FakeCheck( 'test.queue', Result::pass( 'Done' ) );
		$this->register_checks( $check );
		$runner = new Runner();

		$id    = $runner->enqueue( 'test.queue', 1 );
		$again = $runner->enqueue( 'test.queue', 1 );

		$this->assertSame( $id, $again );
		$this->assertSame( [], $check->contexts, 'Enqueue must not execute the check' );
		$this->assertSame( 'queued', ( new RunsRepository() )->find( $id )['status'] );
		$this->assertNotFalse( wp_next_scheduled( Scheduler::QUEUE_HOOK ) );

		$row = $runner->run_queued( $id );
		$this->assertSame( 'pass', $row['status'] );
		$this->assertSame( 'manual', $row['trigger_type'] );
		$this->assertSame( $id, $row['id'] );
		$this->assertWPError( $runner->run_queued( $id ), 'A finished run cannot run again' );
	}

	public function test_run_completed_action_fires(): void {
		$this->register_checks( new FakeCheck( 'test.done', Result::pass( '' ) ) );
		$seen = [];
		add_action(
			'amc_run_completed',
			static function ( array $row ) use ( &$seen ): void {
				$seen[] = $row['check_id'];
			}
		);

		( new Runner() )->run( 'test.done' );

		$this->assertSame( [ 'test.done' ], $seen );
	}

	/**
	 * Multisite: the runner switches to the target site; checks never do.
	 *
	 * @group ms-required
	 */
	public function test_runner_switches_to_the_target_blog(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only (run with WP_MULTISITE=1).' );
		}

		$blog_id = self::factory()->blog->create();
		$seen    = 0;
		$this->register_checks(
			new FakeCheck(
				'test.ms',
				static function ( Context $ctx ) use ( &$seen ): Result {
					$seen = get_current_blog_id();
					return Result::pass( (string) $ctx->blog_id );
				}
			)
		);

		$row = ( new Runner() )->run( 'test.ms', 'cli', 0, $blog_id );

		$this->assertSame( $blog_id, $seen );
		$this->assertSame( (string) $blog_id, $row['summary'] );
		$this->assertSame( $blog_id, $row['blog_id'] );
		$this->assertSame( 1, get_current_blog_id(), 'Restored after the run' );
	}
}
