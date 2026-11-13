<?php
/**
 * Tests for the smoke.urls check.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Checks\Smoke;

use AMC\Checks\Context;
use AMC\Checks\Group;
use AMC\Checks\Schedule;
use AMC\Checks\Severity;
use AMC\Checks\Smoke\SmokeUrls;
use AMC\Checks\Status;
use AMC\Core\Settings;
use AMC\Runner\ProcessedFindings;
use AMC\Runner\Runner;
use AMC\Storage\FindingsRepository;
use AMC\Tests\TestCase;

/**
 * Class SmokeUrlsTest
 */
final class SmokeUrlsTest extends TestCase {

	private const HOME = 'https://example.test/';

	/**
	 * Scenario served for each URL ('ok' by default).
	 *
	 * @var array<string, string>
	 */
	private array $scenarios = [];

	/**
	 * Requests made: url => auth passed.
	 *
	 * @var list<array{url: string, auth: array<string, string>|null}>
	 */
	private array $requests = [];

	/**
	 * Fake clock value in seconds.
	 *
	 * @var float
	 */
	private float $clock = 0.0;

	/**
	 * Seconds the fake clock advances per request.
	 *
	 * @var float
	 */
	private float $tick = 0.0;

	/**
	 * Payloads of amc_findings_processed.
	 *
	 * @var list<ProcessedFindings>
	 */
	private array $processed = [];

	/**
	 * Build the check with fake URLs, transport and clock.
	 *
	 * @param list<array{url: string, required_text?: string, is_default?: bool}>|null $urls Rows (default: home + login).
	 * @param array<string, string>|null                                              $auth Credentials.
	 */
	private function check( ?array $urls = null, ?array $auth = null ): SmokeUrls {
		$urls ??= [
			[
				'url'        => self::HOME,
				'is_default' => true,
			],
			[
				'url'        => self::HOME . 'wp-login.php',
				'is_default' => true,
			],
		];

		$responses = $this->fixture( 'smoke/responses' );

		return new SmokeUrls(
			static fn (): array => $urls,
			function ( string $url, ?array $basic_auth ) use ( $responses ): array {
				$this->requests[] = [
					'url'  => $url,
					'auth' => $basic_auth,
				];
				$this->clock     += $this->tick;

				return $responses[ $this->scenarios[ $url ] ?? 'ok' ];
			},
			fn (): float => $this->clock,
			static fn (): array => $auth ?? [
				'username' => '',
				'password' => '',
				'host'     => 'example.test',
			]
		);
	}

	/**
	 * Context carrying the previous run's data.
	 *
	 * @param array<string, mixed>|null $previous Previous data.
	 * @param string                    $now      Time.
	 */
	private function ctx( ?array $previous = null, string $now = '2026-11-14 10:00:00' ): Context {
		return Context::for_tests( $previous, $now );
	}

	/**
	 * The state row for a URL.
	 *
	 * @param array<string, mixed> $data Result data.
	 * @param string               $url  URL.
	 *
	 * @return array<string, mixed>
	 */
	private function state( array $data, string $url ): array {
		foreach ( $data['urls'] as $row ) {
			if ( $url === $row['url'] ) {
				return $row;
			}
		}

		$this->fail( 'No state for ' . $url );
	}

	public function test_metadata(): void {
		$check = new SmokeUrls();

		$this->assertSame( 'smoke.urls', $check->id() );
		$this->assertSame( Group::SMOKE, $check->group() );
		$this->assertSame( Schedule::HOURLY, $check->schedule() );
		$this->assertNotSame( '', $check->label() );
	}

	public function test_all_urls_passing_is_a_pass(): void {
		$result = $this->check()->run( $this->ctx() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertTrue( $result->complete );
		$this->assertSame( [], $result->findings );
		$this->assertSame( 2, $result->data['counts']['pass'] );

		$home = $this->state( $result->data, self::HOME );
		$this->assertSame( 'pass', $home['status'] );
		$this->assertSame( 200, $home['http_code'] );
		$this->assertSame( 400, $home['response_ms'] );
		$this->assertSame( 0, $home['consecutive_failures'] );
	}

	public function test_a_single_failure_raises_no_finding(): void {
		$this->scenarios[ self::HOME ] = 'error500';

		$result = $this->check()->run( $this->ctx() );

		$this->assertSame( Status::WARN, $result->status );
		$this->assertSame( [], $result->findings );
		$this->assertTrue( $result->complete );

		$home = $this->state( $result->data, self::HOME );
		$this->assertSame( 'fail', $home['status'] );
		$this->assertSame( 1, $home['consecutive_failures'] );
		$this->assertSame( [ 'HTTP status 500, expected 200.' ], $home['reasons'] );
	}

	public function test_two_failures_in_a_row_raise_a_high_finding(): void {
		$this->scenarios[ self::HOME ] = 'error500';
		$check                         = $this->check();

		$first  = $check->run( $this->ctx() );
		$second = $check->run( $this->ctx( $first->data, '2026-11-14 11:00:00' ) );

		$this->assertSame( Status::FAIL, $second->status );
		$this->assertCount( 1, $second->findings );
		$this->assertSame( 'smoke:' . md5( self::HOME ), $second->findings[0]->dedupe_key );
		$this->assertSame( Severity::HIGH, $second->findings[0]->severity );
		$this->assertSame( 2, $second->findings[0]->data['consecutive_failures'] );
		$this->assertStringContainsString( self::HOME, $second->findings[0]->title );
	}

	public function test_a_pass_resets_the_streak(): void {
		$this->scenarios[ self::HOME ] = 'error500';
		$check                         = $this->check();

		$first = $check->run( $this->ctx() );
		unset( $this->scenarios[ self::HOME ] );
		$second = $check->run( $this->ctx( $first->data, '2026-11-14 11:00:00' ) );
		$this->scenarios[ self::HOME ] = 'error500';
		$third = $check->run( $this->ctx( $second->data, '2026-11-14 12:00:00' ) );

		$this->assertSame( Status::PASS, $second->status );
		$this->assertSame( Status::WARN, $third->status, 'fail, pass, fail is not two in a row' );
		$this->assertSame( [], $third->findings );
	}

	public function test_slow_php_error_and_required_text_failures_have_translated_reasons(): void {
		$slow_url = self::HOME . 'slow';
		$err_url  = self::HOME . 'err';
		$txt_url  = self::HOME . 'txt';

		$this->scenarios[ $slow_url ] = 'slow';
		$this->scenarios[ $err_url ]  = 'php_error';

		$result = $this->check(
			[
				[ 'url' => $slow_url ],
				[ 'url' => $err_url ],
				[
					'url'           => $txt_url,
					'required_text' => 'Maintenance',
				],
			]
		)->run( $this->ctx() );

		$this->assertSame( 3, $result->data['counts']['fail'] );
		$this->assertSame( [ 'The response took 3.40 s, over the 3 s limit.' ], $this->state( $result->data, $slow_url )['reasons'] );
		$this->assertSame( [ 'PHP error text was found in the page.' ], $this->state( $result->data, $err_url )['reasons'] );
		$this->assertSame( [ 'The required text "Maintenance" was not found.' ], $this->state( $result->data, $txt_url )['reasons'] );
	}

	public function test_loopback_blocked_is_its_own_status_and_not_a_failure(): void {
		$this->scenarios[ self::HOME ]                = 'blocked';
		$this->scenarios[ self::HOME . 'wp-login.php' ] = 'wall';

		$check  = $this->check();
		$first  = $check->run( $this->ctx() );
		$second = $check->run( $this->ctx( $first->data, '2026-11-14 11:00:00' ) );

		$this->assertSame( Status::INFO, $second->status );
		$this->assertSame( [], $second->findings );
		$this->assertSame( 2, $second->data['counts']['loopback_blocked'] );
		$this->assertSame( 0, $second->data['counts']['fail'] );

		$home = $this->state( $second->data, self::HOME );
		$this->assertSame( 'loopback_blocked', $home['status'] );
		$this->assertNull( $home['http_code'] );
		$this->assertSame( 0, $home['consecutive_failures'] );
		$this->assertStringContainsString( 'loopback blocked', $home['reasons'][0] );
		$this->assertStringContainsString( 'basic auth', $this->state( $second->data, self::HOME . 'wp-login.php' )['reasons'][0] );
	}

	public function test_blocked_runs_neither_extend_nor_reset_the_streak(): void {
		$this->scenarios[ self::HOME ] = 'error500';
		$check                         = $this->check();

		$first                         = $check->run( $this->ctx() );
		$this->scenarios[ self::HOME ] = 'blocked';
		$second                        = $check->run( $this->ctx( $first->data, '2026-11-14 11:00:00' ) );

		$this->assertSame( 1, $this->state( $second->data, self::HOME )['consecutive_failures'] );
		$this->assertSame( [], $second->findings );

		$this->scenarios[ self::HOME ] = 'error500';
		$third                         = $check->run( $this->ctx( $second->data, '2026-11-14 12:00:00' ) );
		$this->assertCount( 1, $third->findings );
	}

	public function test_a_timeout_is_a_failure_not_a_blocked_loopback(): void {
		$this->scenarios[ self::HOME ] = 'timeout';

		$result = $this->check()->run( $this->ctx() );

		$this->assertSame( 'fail', $this->state( $result->data, self::HOME )['status'] );
	}

	public function test_credentials_go_only_to_this_sites_host(): void {
		$this->check(
			[
				[ 'url' => self::HOME ],
				[ 'url' => 'https://other.example.org/status' ],
			],
			[
				'username' => 'staging',
				'password' => 's3cret',
				'host'     => 'example.test',
			]
		)->run( $this->ctx() );

		$this->assertSame(
			[
				'username' => 'staging',
				'password' => 's3cret',
			],
			$this->requests[0]['auth']
		);
		$this->assertNull( $this->requests[1]['auth'], 'Never leak the loopback credentials to an external host.' );
	}

	public function test_budget_batches_urls_and_resumes_with_a_partial_result(): void {
		$urls = [];
		for ( $i = 0; $i < 6; $i++ ) {
			$urls[] = [ 'url' => self::HOME . 'p' . $i ];
		}

		// Each request costs 5 s of the 16 s budget (+4 s timeout reserve): 3 fit per run.
		$this->tick = 5.0;
		$check      = $this->check( $urls );

		$first = $check->run( $this->ctx() );
		$this->assertFalse( $first->complete, 'Not every URL was reached' );
		$this->assertFalse( $first->data['complete'] );
		$this->assertSame( 3, $first->data['cursor'] );
		$this->assertCount( 3, $this->requests );
		$this->assertSame( 3, $first->data['counts']['pass'] );
		$this->assertSame( 3, $first->data['counts']['unchecked'] );

		$this->clock = 0.0;
		$second      = $check->run( $this->ctx( $first->data, '2026-11-14 11:00:00' ) );

		$this->assertTrue( $second->complete );
		$this->assertSame( 0, $second->data['cursor'] );
		$this->assertSame( self::HOME . 'p3', $this->requests[3]['url'], 'The second run starts at the cursor' );
		$this->assertSame( 6, $second->data['counts']['pass'] );
	}

	public function test_history_and_daily_buckets_feed_the_pass_rate_and_trend(): void {
		$check = $this->check( [ [ 'url' => self::HOME ] ] );
		$data  = null;

		for ( $i = 0; $i < 30; $i++ ) {
			$this->scenarios[ self::HOME ] = 0 === $i % 10 ? 'error500' : 'ok';
			$data                          = $check->run( $this->ctx( $data, gmdate( 'Y-m-d H:i:s', strtotime( '2026-11-01 00:00:00 UTC' ) + $i * 3600 * 12 ) ) )->data;
		}

		$home = $this->state( $data, self::HOME );

		$this->assertCount( 24, $home['history'], 'History is capped at 24 checks' );
		$this->assertCount( 15, $home['daily'], '30 runs twelve hours apart cover 15 days' );
		$this->assertSame( 2, $home['daily']['2026-11-01']['n'] );
		$this->assertSame( 1, $home['daily']['2026-11-01']['ok'], 'The first run of the day failed (i = 0)' );
		$this->assertSame( 600, $home['daily']['2026-11-01']['ms_sum'], '200 ms (500 page) + 400 ms' );
	}

	public function test_urls_removed_from_settings_drop_their_state_and_duplicates_collapse(): void {
		$check = $this->check( [ [ 'url' => self::HOME ], [ 'url' => self::HOME ], [ 'url' => '' ] ] );

		$first = $check->run( $this->ctx() );
		$this->assertCount( 1, $first->data['urls'] );

		$only_login = $this->check( [ [ 'url' => self::HOME . 'wp-login.php' ] ] );
		$second     = $only_login->run( $this->ctx( $first->data ) );
		$this->assertSame( [ self::HOME . 'wp-login.php' ], array_column( $second->data['urls'], 'url' ) );
	}

	public function test_no_urls_is_info(): void {
		$result = $this->check( [] )->run( $this->ctx() );

		$this->assertSame( Status::INFO, $result->status );
		$this->assertSame( [], $result->data['urls'] );
	}

	public function test_default_urls_are_home_login_latest_post_and_configured_urls(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		( new Settings() );
		Settings::update(
			[
				'smoke_urls' => [
					[
						'url'           => 'https://example.test/pricing',
						'required_text' => 'Plans',
					],
				],
			]
		);

		$urls = SmokeUrls::default_urls();

		$this->assertSame( home_url( '/' ), $urls[0]['url'] );
		$this->assertSame( wp_login_url(), $urls[1]['url'] );
		$this->assertSame( get_permalink( $post_id ), $urls[2]['url'] );
		$this->assertTrue( $urls[2]['is_default'] );
		$this->assertSame( 'https://example.test/pricing', $urls[3]['url'] );
		$this->assertSame( 'Plans', $urls[3]['required_text'] );
		$this->assertFalse( $urls[3]['is_default'] );
	}

	public function test_ecommerce_sites_use_the_latest_product(): void {
		register_post_type( 'product', [ 'public' => true ] );
		self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$product = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_type'   => 'product',
			]
		);
		Settings::update( [ 'site_type' => 'ecommerce' ] );

		$urls = SmokeUrls::default_urls();

		$this->assertSame( get_permalink( $product ), $urls[2]['url'] );
		unregister_post_type( 'product' );
	}

	/**
	 * Through the real runner: one failure alerts nothing, the second creates a new finding once, a pass resolves it.
	 */
	public function test_runner_creates_a_finding_on_the_second_consecutive_failure_and_resolves_on_recovery(): void {
		add_action(
			'amc_findings_processed',
			function ( ProcessedFindings $p ): void {
				$this->processed[] = $p;
			}
		);

		$this->register_checks( $this->check( [ [ 'url' => self::HOME ] ] ) );
		$runner = new Runner();
		$repo   = new FindingsRepository();

		$this->scenarios[ self::HOME ] = 'error500';
		$runner->run( 'smoke.urls' );
		$this->assertCount( 0, $this->processed[0]->new, 'One failure raises nothing' );

		$runner->run( 'smoke.urls' );
		$this->assertCount( 1, $this->processed[1]->new );

		$runner->run( 'smoke.urls' );
		$this->assertCount( 0, $this->processed[2]->new, 'Alerted once while it keeps failing' );
		$this->assertCount( 1, $this->processed[2]->ongoing );

		unset( $this->scenarios[ self::HOME ] );
		$runner->run( 'smoke.urls' );
		$this->assertCount( 1, $this->processed[3]->resolved );
		$this->assertSame( [], $repo->unresolved_for_check( 'smoke.urls' ) );
	}
}
