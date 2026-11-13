<?php
/**
 * Tests for the vuln.match check, the Matcher and the FixtureProvider.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Checks\Vulnerabilities;

use AMC\Checks\Context;
use AMC\Checks\Group;
use AMC\Checks\Schedule;
use AMC\Checks\Severity;
use AMC\Checks\Status;
use AMC\Checks\Vulnerabilities\VulnMatchCheck;
use AMC\Runner\ProcessedFindings;
use AMC\Runner\Runner;
use AMC\Storage\FindingsRepository;
use AMC\Tests\TestCase;
use AMC\Vulnerability\Component;
use AMC\Vulnerability\FixtureProvider;
use AMC\Vulnerability\Matcher;
use AMC\Vulnerability\NullProvider;
use AMC\Vulnerability\ProviderInterface;
use AMC\Vulnerability\VulnMatch;

/**
 * Class VulnMatchCheckTest
 */
final class VulnMatchCheckTest extends TestCase {

	/**
	 * Installed plugin version the fake inventory reports.
	 *
	 * @var string
	 */
	private string $forms_version = '2.3.0';

	/**
	 * Payloads of amc_findings_processed.
	 *
	 * @var list<ProcessedFindings>
	 */
	private array $processed = [];

	/**
	 * Fake inventory.
	 *
	 * @return list<Component>
	 */
	private function inventory(): array {
		return [
			new Component( 'core', 'wordpress', '6.9.1', 'WordPress' ),
			new Component( 'plugin', 'contact-forms', $this->forms_version, 'Contact Forms' ),
			new Component( 'plugin', 'gallery-lite', '2.0', 'Gallery Lite' ),
			new Component( 'theme', 'twentytwenty', '2.1', 'Twenty Twenty' ),
		];
	}

	/**
	 * Check using a provider and the fake inventory.
	 *
	 * @param ProviderInterface $provider Provider.
	 */
	private function check_with( ProviderInterface $provider ): VulnMatchCheck {
		return new VulnMatchCheck( new Matcher( fn (): array => $this->inventory(), static fn (): ProviderInterface => $provider ) );
	}

	/**
	 * A fixture provider with the standard entries.
	 */
	private function fixture_provider(): FixtureProvider {
		return new FixtureProvider( $this->fixture( 'vuln-match/entries' ) );
	}

	/**
	 * Provider double that errors.
	 *
	 * @param string|null $error Reported error ('' = throw instead).
	 */
	private function failing_provider( ?string $error ): ProviderInterface {
		return new class( $error ) implements ProviderInterface {
			/**
			 * Constructor.
			 *
			 * @param string|null $error Error.
			 */
			public function __construct( private ?string $error ) {}

			public function id(): string {
				return 'broken';
			}

			public function is_ready(): bool {
				return true;
			}

			public function match( array $components ): array {
				if ( '' === $this->error ) {
					throw new \RuntimeException( 'API exploded' );
				}

				return [];
			}

			public function last_error(): ?string {
				return '' === $this->error ? null : $this->error;
			}
		};
	}

	public function test_metadata(): void {
		$check = new VulnMatchCheck();

		$this->assertSame( 'vuln.match', $check->id() );
		$this->assertSame( Group::VULNERABILITIES, $check->group() );
		$this->assertSame( Schedule::TWICE_DAILY, $check->schedule() );
		$this->assertNotSame( '', $check->label() );
	}

	public function test_no_provider_is_partial_info(): void {
		$result = $this->check_with( new NullProvider() )->run( Context::for_tests() );

		$this->assertSame( Status::INFO, $result->status );
		$this->assertFalse( $result->complete );
		$this->assertSame( [], $result->findings );
		$this->assertFalse( $result->data['provider_ready'] );
	}

	public function test_matches_become_findings_with_the_documented_key_and_severity(): void {
		$result = $this->check_with( $this->fixture_provider() )->run( Context::for_tests() );

		$this->assertSame( Status::FAIL, $result->status );
		$this->assertTrue( $result->complete );
		$this->assertCount( 2, $result->findings, 'forms 2.3.0 < 2.4.1; gallery 2.0 is outside >=1.0,<=1.8; the theme entry matches every version' );

		$by_key = [];
		foreach ( $result->findings as $finding ) {
			$by_key[ $finding->dedupe_key ] = $finding;
		}

		$this->assertArrayHasKey( 'plugin:contact-forms:2.3.0:CVE-2026-1001', $by_key );
		$this->assertArrayHasKey( 'theme:twentytwenty:2.1:CVE-2026-3003', $by_key );

		$critical = $by_key['plugin:contact-forms:2.3.0:CVE-2026-1001'];
		$this->assertSame( Severity::CRITICAL, $critical->severity );
		$this->assertSame( '2.3.0', $critical->data['installed_version'] );
		$this->assertSame( 'CVE-2026-1001', $critical->data['reference'] );
		$this->assertSame( 9.8, $critical->data['cvss'] );

		$this->assertSame( 1, $result->data['counts']['critical'] );
		$this->assertSame( 1, $result->data['counts']['low'] );
		$this->assertSame( 0, $result->data['counts']['medium'] );
		$this->assertCount( 2, $result->data['matches'] );
	}

	public function test_only_medium_and_low_matches_warn(): void {
		$this->forms_version = '2.4.1';

		$result = $this->check_with( $this->fixture_provider() )->run( Context::for_tests() );

		$this->assertSame( Status::WARN, $result->status, 'Only the low theme match is left' );
		$this->assertCount( 1, $result->findings );
	}

	public function test_no_matches_pass(): void {
		$result = ( new VulnMatchCheck( new Matcher( fn (): array => [ new Component( 'plugin', 'contact-forms', '2.4.1' ) ], fn (): ProviderInterface => $this->fixture_provider() ) ) )->run( Context::for_tests() );

		$this->assertSame( Status::PASS, $result->status );
		$this->assertTrue( $result->complete );
	}

	public function test_provider_last_error_is_warn_partial_with_the_error_in_data(): void {
		$result = $this->check_with( $this->failing_provider( 'Rate limited' ) )->run( Context::for_tests() );

		$this->assertSame( Status::WARN, $result->status );
		$this->assertFalse( $result->complete );
		$this->assertSame( 'Rate limited', $result->data['provider_error'] );
		$this->assertStringContainsString( 'Rate limited', $result->summary );
		$this->assertSame( [], $result->findings );
	}

	public function test_provider_exception_is_caught(): void {
		$result = $this->check_with( $this->failing_provider( '' ) )->run( Context::for_tests() );

		$this->assertSame( Status::WARN, $result->status );
		$this->assertFalse( $result->complete );
		$this->assertSame( 'API exploded', $result->data['provider_error'] );
	}

	public function test_matches_for_components_that_are_not_installed_are_dropped(): void {
		$provider = new FixtureProvider( $this->fixture( 'vuln-match/entries' ) );
		$matcher  = new Matcher( static fn (): array => [ new Component( 'plugin', 'unrelated', '1.0' ) ], static fn (): ProviderInterface => $provider );

		$this->assertSame( [], $matcher->run()['matches'] );

		$liar = new class() implements ProviderInterface {
			public function id(): string {
				return 'liar';
			}

			public function is_ready(): bool {
				return true;
			}

			public function match( array $components ): array {
				return [ new VulnMatch( 'plugin', 'ghost', 'CVE-X', 'Phantom', Severity::HIGH ) ];
			}

			public function last_error(): ?string {
				return null;
			}
		};

		$outcome = ( new Matcher( fn (): array => $this->inventory(), static fn (): ProviderInterface => $liar ) )->run();
		$this->assertSame( [], $outcome['matches'] );
	}

	public function test_default_inventory_contains_core_plugins_and_themes(): void {
		$components = ( new Matcher() )->components();
		$types      = array_unique( array_map( static fn ( Component $c ): string => $c->type, $components ) );

		$this->assertContains( 'core', $types );
		$this->assertContains( 'theme', $types );
		$this->assertSame( 'wordpress', $components[0]->slug );
		$this->assertSame( wp_get_wp_version(), $components[0]->version );
	}

	public function test_long_dedupe_keys_are_capped(): void {
		$key = VulnMatchCheck::dedupe_key( 'plugin', str_repeat( 'a', 150 ), '1.0', str_repeat( 'r', 100 ) );

		$this->assertLessThanOrEqual( 191, strlen( $key ) );
	}

	/**
	 * Version ranges.
	 *
	 * @dataProvider provide_ranges
	 *
	 * @param string $version  Installed.
	 * @param string $range    Range.
	 * @param bool   $expected Whether it is affected.
	 */
	public function test_fixture_ranges( string $version, string $range, bool $expected ): void {
		$this->assertSame( $expected, FixtureProvider::in_range( $version, $range ) );
	}

	/**
	 * Range cases.
	 *
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public static function provide_ranges(): array {
		return [
			'star'             => [ '9.9', '*', true ],
			'less than'        => [ '1.9', '<2.0', true ],
			'not less than'    => [ '2.0', '<2.0', false ],
			'less or equal'    => [ '2.0', '<=2.0', true ],
			'between'          => [ '1.5', '>=1.0,<2.0', true ],
			'above between'    => [ '2.1', '>=1.0,<2.0', false ],
			'bare equality'    => [ '1.2.3', '1.2.3', true ],
			'bare inequality'  => [ '1.2.4', '1.2.3', false ],
			'garbage is false' => [ '1.0', '<<', false ],
		];
	}

	public function test_fixture_provider_is_enabled_by_filter_and_read_from_data_filter(): void {
		$this->assertInstanceOf( NullProvider::class, \AMC\Vulnerability\Providers::current() );

		$fixture = new FixtureProvider();
		$fixture->register_hooks();
		$this->assertInstanceOf( NullProvider::class, \AMC\Vulnerability\Providers::current(), 'Disabled by default' );

		add_filter( FixtureProvider::ENABLE_FILTER, '__return_true' );
		add_filter( FixtureProvider::DATA_FILTER, fn (): array => $this->fixture( 'vuln-match/entries' ) );

		$provider = \AMC\Vulnerability\Providers::current();

		$this->assertInstanceOf( FixtureProvider::class, $provider );
		$this->assertTrue( $provider->is_ready() );
		$this->assertCount( 1, $provider->match( [ new Component( 'plugin', 'contact-forms', '2.0' ) ] ) );
	}

	public function test_fixture_from_a_missing_file_reports_an_error(): void {
		$provider = FixtureProvider::from_file( '/nonexistent/fixture.php' );

		$this->assertFalse( $provider->is_ready() );
		$this->assertNotNull( $provider->last_error() );
	}

	/**
	 * Acceptance: a CVE from a test provider appears, alerts once, and resolves when the plugin is updated.
	 */
	public function test_cve_appears_alerts_once_and_resolves_when_the_plugin_is_updated(): void {
		$this->record_processed();

		$repo = new FindingsRepository();
		$this->register_checks( $this->check_with( $this->fixture_provider() ) );
		$runner = new Runner();

		// Run 1: the critical CVE and the theme one appear as new, un-alerted findings.
		$runner->run( 'vuln.match' );
		$this->assertCount( 2, $this->processed[0]->new );
		$this->assertArrayHasKey( 'plugin:contact-forms:2.3.0:CVE-2026-1001', $repo->unresolved_for_check( 'vuln.match' ) );

		// Run 2: still there. Notify alerts on `new` only, so nothing alerts again.
		$runner->run( 'vuln.match' );
		$this->assertCount( 0, $this->processed[1]->new );
		$this->assertCount( 2, $this->processed[1]->ongoing );

		// The plugin is updated to the fixed version: its CVE resolves, the theme one stays.
		$this->forms_version = '2.4.1';
		$runner->run( 'vuln.match' );

		$this->assertSame( [ 'plugin:contact-forms:2.3.0:CVE-2026-1001' ], array_column( $this->processed[2]->resolved, 'dedupe_key' ) );
		$this->assertCount( 0, $this->processed[2]->new );
		$this->assertSame( [ 'theme:twentytwenty:2.1:CVE-2026-3003' ], array_keys( $repo->unresolved_for_check( 'vuln.match' ) ) );
	}

	public function test_provider_error_run_keeps_existing_findings_open(): void {
		$this->record_processed();

		$repo   = new FindingsRepository();
		$runner = new Runner();

		// A working provider first, then a failing one via a mutable holder.
		$holder = (object) [ 'provider' => $this->fixture_provider() ];
		$this->register_checks( new VulnMatchCheck( new Matcher( fn (): array => $this->inventory(), fn (): ProviderInterface => $holder->provider ) ) );

		$runner->run( 'vuln.match' );
		$this->assertCount( 2, $repo->unresolved_for_check( 'vuln.match' ) );

		$holder->provider = $this->failing_provider( 'Provider down' );
		$row              = $runner->run( 'vuln.match' );

		$this->assertSame( 'warn', $row['status'] );
		$this->assertSame( 'Provider down', $row['data']['provider_error'] );
		$this->assertCount( 0, $this->processed[1]->resolved, 'A provider error never resolves findings' );
		$this->assertCount( 2, $repo->unresolved_for_check( 'vuln.match' ) );

		// When the provider recovers the findings are simply ongoing, not new.
		$holder->provider = $this->fixture_provider();
		$runner->run( 'vuln.match' );
		$this->assertCount( 0, $this->processed[2]->new );
		$this->assertCount( 2, $this->processed[2]->ongoing );
	}

	/**
	 * Record amc_findings_processed payloads into $this->processed.
	 */
	private function record_processed(): void {
		add_action(
			'amc_findings_processed',
			function ( ProcessedFindings $p ): void {
				$this->processed[] = $p;
			}
		);
	}
}
