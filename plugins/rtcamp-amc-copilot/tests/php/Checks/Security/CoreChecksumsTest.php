<?php
/**
 * Tests for the security.checksums check.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Checks\Security;

use AMC\Checks\Context;
use AMC\Checks\Group;
use AMC\Checks\Schedule;
use AMC\Checks\Security\CoreChecksums;
use AMC\Checks\Severity;
use AMC\Checks\Status;
use AMC\Runner\ProcessedFindings;
use AMC\Runner\Runner;
use AMC\Storage\FindingsRepository;
use AMC\Tests\Support\ReplacesChecks;
use AMC\Tests\TestCase;

/**
 * Class CoreChecksumsTest
 */
final class CoreChecksumsTest extends TestCase {

	use ReplacesChecks;

	/**
	 * File "contents" on the fake disk (path => content). A path left out is missing.
	 *
	 * @var array<string, string>
	 */
	private array $disk = [];

	/**
	 * Fake clock.
	 *
	 * @var float
	 */
	private float $clock = 0.0;

	/**
	 * Seconds each hashed file adds to the clock.
	 *
	 * @var float
	 */
	private float $tick = 0.0;

	/**
	 * Whether the checksums API is "down".
	 *
	 * @var bool
	 */
	private bool $api_down = false;

	/**
	 * Payloads of amc_findings_processed.
	 *
	 * @var list<ProcessedFindings>
	 */
	private array $processed = [];

	public function set_up(): void {
		parent::set_up();

		$this->disk = [
			'index.php'               => 'index',
			'license.txt'             => 'license',
			'readme.html'             => 'readme',
			'wp-admin/index.php'      => 'admin-index',
			'wp-includes/version.php' => 'version',
			'wp-includes/load.php'    => 'load',
			'wp-login.php'            => 'login',
			'wp-settings.php'         => 'settings',
		];
	}

	/**
	 * The check against the fake disk, API and clock.
	 */
	private function check(): CoreChecksums {
		return new CoreChecksums(
			fn (): array|false => $this->api_down ? false : $this->fixture( 'security-checksums/checksums' ),
			function ( string $path ): ?string {
				$this->clock += $this->tick;

				return isset( $this->disk[ $path ] ) ? md5( $this->disk[ $path ] ) : null;
			},
			fn (): float => $this->clock,
			static fn (): string => '6.9.1',
			static fn (): string => 'en_US'
		);
	}

	public function test_metadata(): void {
		$check = new CoreChecksums();

		$this->assertSame( 'security.checksums', $check->id() );
		$this->assertSame( Group::SECURITY, $check->group() );
		$this->assertSame( Schedule::DAILY, $check->schedule() );
		$this->assertNotSame( '', $check->label() );
	}

	public function test_matching_files_pass_and_wp_content_is_skipped(): void {
		$result = $this->check()->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertTrue( $result->complete );
		$this->assertSame( [], $result->findings );
		$this->assertSame( 8, $result->data['total'], 'wp-content/index.php is skipped' );
		$this->assertSame( 8, $result->data['checked'] );
		$this->assertTrue( $result->data['done'] );
	}

	public function test_modified_and_missing_files_become_findings_named_by_path(): void {
		$this->disk['wp-includes/load.php'] = 'EVIL';
		unset( $this->disk['readme.html'], $this->disk['wp-login.php'] );

		$result = $this->check()->run( Context::for_tests() );
		$by_key = [];
		foreach ( $result->findings as $finding ) {
			$by_key[ $finding->dedupe_key ] = $finding;
		}

		$this->assertSame( Status::FAIL, $result->status );
		$this->assertCount( 3, $by_key );
		$this->assertSame( Severity::HIGH, $by_key['core-file:wp-includes/load.php']->severity );
		$this->assertSame( 'modified', $by_key['core-file:wp-includes/load.php']->data['issue'] );
		$this->assertStringContainsString( 'wp-includes/load.php', $by_key['core-file:wp-includes/load.php']->title );
		$this->assertSame( Severity::MEDIUM, $by_key['core-file:wp-login.php']->severity );
		$this->assertSame( Severity::LOW, $by_key['core-file:readme.html']->severity, 'Optional files are low' );
		$this->assertSame( 1, $result->data['modified'] );
		$this->assertSame( 2, $result->data['missing'] );
	}

	public function test_only_missing_files_warn(): void {
		unset( $this->disk['wp-login.php'] );

		$this->assertSame( Status::WARN, $this->check()->run( Context::for_tests() )->status );
	}

	public function test_unavailable_checksums_are_partial_info(): void {
		$this->api_down = true;

		$result = $this->check()->run( Context::for_tests() );

		$this->assertSame( Status::INFO, $result->status );
		$this->assertFalse( $result->complete );
		$this->assertSame( 'checksums_unavailable', $result->data['error'] );
		$this->assertSame( [], $result->findings );
	}

	public function test_batches_across_three_runs_return_partial_except_the_last(): void {
		$this->disk['wp-includes/load.php'] = 'EVIL'; // Sorted position 5 of 8: lands in batch 2.
		$this->tick                         = 5.0;     // Three files fit in the 12 s batch.
		$check                              = $this->check();

		$first = $check->run( Context::for_tests() );
		$this->assertFalse( $first->complete );
		$this->assertSame( 3, $first->data['cursor'] );
		$this->assertFalse( $first->data['done'] );
		$this->assertSame( Status::INFO, $first->status );
		$this->assertSame( [], $first->findings );

		$this->clock = 0.0;
		$second      = $check->run( Context::for_tests( $first->data ) );
		$this->assertFalse( $second->complete );
		$this->assertSame( 6, $second->data['cursor'] );
		$this->assertCount( 1, $second->findings );

		$this->clock = 0.0;
		$third       = $check->run( Context::for_tests( $second->data ) );
		$this->assertTrue( $third->complete );
		$this->assertTrue( $third->data['done'] );
		$this->assertSame( 8, $third->data['checked'] );
		$this->assertCount( 1, $third->findings, 'The last batch returns the whole pass' );
		$this->assertSame( 'core-file:wp-includes/load.php', $third->findings[0]->dedupe_key );

		// The next run starts a new pass at 0.
		$this->clock = 0.0;
		$fourth      = $check->run( Context::for_tests( $third->data ) );
		$this->assertSame( 3, $fourth->data['cursor'] );
		$this->assertSame( [], $fourth->data['found'] );
	}

	public function test_a_new_wordpress_version_restarts_the_pass(): void {
		$partial = [
			'version' => '6.9.0',
			'locale'  => 'en_US',
			'cursor'  => 5,
			'checked' => 5,
			'done'    => false,
			'found'   => [
				[
					'path'  => 'index.php',
					'issue' => 'modified',
				],
			],
		];

		$result = $this->check()->run( Context::for_tests( $partial ) );

		$this->assertSame( 8, $result->data['checked'] );
		$this->assertSame( [], $result->findings );
	}

	/**
	 * Acceptance: three runs, a modified file stays open throughout and resolves only after it is fixed.
	 */
	public function test_modified_file_stays_open_across_three_batched_runs_through_the_runner(): void {
		add_action(
			'amc_findings_processed',
			function ( ProcessedFindings $p ): void {
				$this->processed[] = $p;
			}
		);

		$this->disk['index.php'] = 'EVIL'; // First file: found in batch 1.
		$this->tick              = 5.0;
		$this->replace_checks( $this->check() );
		$runner = new Runner();
		$repo   = new FindingsRepository();
		$key    = 'core-file:index.php';

		$this->clock = 0.0;
		$runner->run( 'security.checksums' );
		$this->assertCount( 1, $this->processed[0]->new );
		$this->assertArrayHasKey( $key, $repo->unresolved_for_check( 'security.checksums' ) );

		$this->clock = 0.0;
		$runner->run( 'security.checksums' );
		$this->assertCount( 0, $this->processed[1]->new, 'Not re-created' );
		$this->assertCount( 0, $this->processed[1]->resolved );
		$this->assertCount( 1, $this->processed[1]->ongoing );

		$this->clock = 0.0;
		$row         = $runner->run( 'security.checksums' );
		$this->assertTrue( $row['data']['done'] );
		$this->assertCount( 0, $this->processed[2]->new );
		$this->assertCount( 0, $this->processed[2]->resolved );
		$this->assertCount( 1, $this->processed[2]->ongoing );
		$this->assertArrayHasKey( $key, $repo->unresolved_for_check( 'security.checksums' ) );

		// Fixed on disk: the next pass resolves it only when it completes (third batch).
		$this->disk['index.php'] = 'index';
		foreach ( [ 3, 4, 5 ] as $n ) {
			$this->clock = 0.0;
			$runner->run( 'security.checksums' );
			$still_open = array_key_exists( $key, $repo->unresolved_for_check( 'security.checksums' ) );
			$this->assertSame( 5 !== $n, $still_open, 'Resolved only by the final batch of the new pass (run ' . $n . ')' );
		}
	}
}
