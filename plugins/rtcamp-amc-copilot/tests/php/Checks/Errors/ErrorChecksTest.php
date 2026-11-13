<?php
/**
 * Tests for errors.fatal, errors.warnings and errors.deprecations.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Checks\Errors;

use AMC\Checks\Context;
use AMC\Checks\Errors\AbstractEventsCheck;
use AMC\Checks\Errors\Deprecations;
use AMC\Checks\Errors\FatalErrors;
use AMC\Checks\Errors\Warnings;
use AMC\Checks\Group;
use AMC\Checks\Schedule;
use AMC\Checks\Severity;
use AMC\Checks\Status;
use AMC\Storage\EventsRepository;
use AMC\Tests\TestCase;

/**
 * Class ErrorChecksTest
 */
final class ErrorChecksTest extends TestCase {

	/**
	 * Calls made to the events source.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * Events source serving the fixture, filtered by type like the repository does.
	 *
	 * @param string $fixture Fixture file inside fixtures/errors (default `events`).
	 */
	private function source( string $fixture = 'events' ): callable {
		$events = $this->fixture( 'errors/' . $fixture );

		return function ( array $args ) use ( $events ): array {
			$this->calls[] = $args;

			return $events[ $args['type'] ] ?? [];
		};
	}

	/**
	 * A context 'now' of 10:30 on the fixture day, optionally with a previous run.
	 *
	 * @param string|null $previous_checked_at Previous run's `checked_at`.
	 */
	private function ctx( ?string $previous_checked_at = null ): Context {
		return Context::for_tests( null === $previous_checked_at ? null : [ 'checked_at' => $previous_checked_at ], '2026-11-14 10:30:00' );
	}

	public function test_metadata(): void {
		$expected = [
			[ new FatalErrors(), 'errors.fatal' ],
			[ new Warnings(), 'errors.warnings' ],
			[ new Deprecations(), 'errors.deprecations' ],
		];

		foreach ( $expected as [ $check, $id ] ) {
			$this->assertInstanceOf( AbstractEventsCheck::class, $check );
			$this->assertSame( $id, $check->id() );
			$this->assertSame( Group::ERRORS, $check->group() );
			$this->assertSame( Schedule::HOURLY, $check->schedule() );
			$this->assertNotSame( '', $check->label() );
		}
	}

	public function test_fatal_raises_one_finding_per_signature(): void {
		$result = ( new FatalErrors( $this->source() ) )->run( $this->ctx() );

		$this->assertSame( Status::FAIL, $result->status );
		$this->assertCount( 2, $result->findings );
		$this->assertSame( str_repeat( 'a', 32 ), $result->findings[0]->dedupe_key, 'Highest count first, key = signature' );
		$this->assertSame( Severity::HIGH, $result->findings[0]->severity );
		$this->assertStringContainsString( 'broken.php:42', $result->findings[0]->title );
		$this->assertSame( 8, $result->data['events'] );
		$this->assertSame( 2, $result->data['signatures'] );
		$this->assertSame( 'plugin:broken', $result->data['items'][0]['source'] );
		$this->assertTrue( $result->complete );
	}

	public function test_nothing_new_passes(): void {
		$result = ( new FatalErrors( $this->source() ) )->run( $this->ctx( '2026-11-14 10:25:00' ) );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertSame( [], $result->findings );
		$this->assertSame( 0, $result->data['events'] );
	}

	public function test_only_events_after_the_previous_run_count(): void {
		// The previous run was at 10:10: the 10:05 fatal was already reported, the 10:20 one is new.
		$result = ( new FatalErrors( $this->source() ) )->run( $this->ctx( '2026-11-14T10:10:00+00:00' ) );

		$this->assertCount( 1, $result->findings );
		$this->assertSame( str_repeat( 'a', 32 ), $result->findings[0]->dedupe_key );
	}

	public function test_window_starts_at_the_previous_runs_hour_or_one_hour_back(): void {
		( new FatalErrors( $this->source() ) )->run( $this->ctx( '2026-11-14T08:40:00+00:00' ) );
		( new FatalErrors( $this->source() ) )->run( $this->ctx() );

		$this->assertSame( '2026-11-14 08:00:00', $this->calls[0]['since'] );
		$this->assertSame( '2026-11-14 09:00:00', $this->calls[1]['since'], 'First run: one hour before 10:30 -> the 09:00 hour' );
		$this->assertSame( 1, $this->calls[0]['blog_id'] );
	}

	public function test_warnings_and_notices_are_grouped_per_source(): void {
		$result = ( new Warnings( $this->source() ) )->run( $this->ctx() );

		$this->assertSame( Status::WARN, $result->status );
		$this->assertCount( 2, $result->findings );

		$noisy = $result->findings[0];
		$this->assertSame( 'source:plugin:noisy', $noisy->dedupe_key );
		$this->assertSame( Severity::LOW, $noisy->severity );
		$this->assertSame( 255, $noisy->data['count'], '200 + 50 warnings + 5 notice' );
		$this->assertSame( 3, $noisy->data['signatures'] );
		$this->assertSame( 'source:core', $result->findings[1]->dedupe_key );
	}

	public function test_deprecations_are_grouped_per_caller(): void {
		$result = ( new Deprecations( $this->source() ) )->run( $this->ctx() );

		$this->assertSame( Status::INFO, $result->status );
		$this->assertCount( 2, $result->findings, 'old.php (two signatures) and the child theme file' );
		$this->assertSame( 'caller:' . md5( 'plugin:old|/srv/wp-content/plugins/old/old.php' ), $result->findings[0]->dedupe_key );
		$this->assertSame( 16, $result->findings[0]->data['count'] );
		$this->assertSame( 2, $result->findings[0]->data['signatures'] );
	}

	public function test_clean_site_passes_for_every_check(): void {
		$none = static fn (): array => [];

		foreach ( [ new FatalErrors( $none ), new Warnings( $none ), new Deprecations( $none ) ] as $check ) {
			$result = $check->run( $this->ctx() );

			$this->assertSame( Status::PASS, $result->status, $check->id() );
			$this->assertSame( [], $result->findings );
		}
	}

	public function test_findings_are_capped_and_the_overflow_is_reported(): void {
		$rows = [];
		for ( $i = 0; $i < 60; $i++ ) {
			$rows[] = [
				'signature'  => md5( (string) $i ),
				'type'       => 'fatal',
				'message'    => 'm',
				'source'     => 'plugin:p',
				'file'       => '/f.php',
				'line'       => $i,
				'count'      => $i + 1,
				'first_seen' => '2026-11-14 10:00:00',
				'last_seen'  => '2026-11-14 10:20:00',
			];
		}

		$result = ( new FatalErrors( static fn (): array => $rows ) )->run( $this->ctx() );

		$this->assertCount( 50, $result->findings );
		$this->assertSame( 10, $result->data['truncated'] );
	}

	public function test_default_source_reads_the_events_table(): void {
		( new EventsRepository() )->upsert_many(
			[
				[
					'type'      => 'fatal',
					'signature' => md5( 'real' ),
					'message'   => 'Real fatal',
					'source'    => 'plugin:real',
					'file'      => '/real.php',
					'line'      => 3,
					'hour'      => gmdate( 'Y-m-d H:00:00' ),
					'count'     => 2,
					'last_seen' => gmdate( 'Y-m-d H:i:s' ),
				],
			]
		);

		$result = ( new FatalErrors() )->run( Context::for_tests() );

		$this->assertCount( 1, $result->findings );
		$this->assertSame( md5( 'real' ), $result->findings[0]->dedupe_key );
	}
}
