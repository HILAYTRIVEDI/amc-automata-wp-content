<?php
/**
 * Tests for AlertRules.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Notify;

use AMC\Notify\AlertRules;
use AMC\Tests\TestCase;

/**
 * Class AlertRulesTest
 */
final class AlertRulesTest extends TestCase {

	/**
	 * Build a finding row.
	 *
	 * @param string               $check_id Check id.
	 * @param string               $severity Severity.
	 * @param array<string, mixed> $data     Data.
	 * @param string               $status   Status.
	 *
	 * @return array<string, mixed>
	 */
	private function row( string $check_id, string $severity = 'medium', array $data = [], string $status = 'open' ): array {
		return [
			'check_id' => $check_id,
			'severity' => $severity,
			'data'     => $data,
			'status'   => $status,
		];
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function catalogue(): array {
		return [
			'updates.core'         => [ 'updates.core', 'digest' ],
			'updates.plugins'      => [ 'updates.plugins', 'digest' ],
			'updates.themes'       => [ 'updates.themes', 'digest' ],
			'updates.php'          => [ 'updates.php', 'digest' ],
			'updates.readiness'    => [ 'updates.readiness', 'none' ],
			'inventory.plugins'    => [ 'inventory.plugins', 'digest' ],
			'inventory.themes'     => [ 'inventory.themes', 'none' ],
			'inventory.mu_dropins' => [ 'inventory.mu_dropins', 'none' ],
			'inventory.editor'     => [ 'inventory.editor', 'none' ],
			'errors.fatal'         => [ 'errors.fatal', 'immediate' ],
			'errors.warnings'      => [ 'errors.warnings', 'digest' ],
			'errors.deprecations'  => [ 'errors.deprecations', 'digest' ],
			'smoke.urls'           => [ 'smoke.urls', 'immediate' ],
			'security.checksums'   => [ 'security.checksums', 'immediate' ],
			'security.config'      => [ 'security.config', 'digest' ],
			'perf.response'        => [ 'perf.response', 'digest' ],
			'perf.database'        => [ 'perf.database', 'digest' ],
			'perf.cache'           => [ 'perf.cache', 'none' ],
			'perf.slow_queries'    => [ 'perf.slow_queries', 'digest' ],
			'health.site_health'   => [ 'health.site_health', 'digest' ],
			'health.cron'          => [ 'health.cron', 'digest' ],
			'users.roles'          => [ 'users.roles', 'digest' ],
			'users.changes'        => [ 'users.changes', 'none' ],
			'hosting.platform'     => [ 'hosting.platform', 'none' ],
			'detect.cdn'           => [ 'detect.cdn', 'none' ],
			'detect.forms'         => [ 'detect.forms', 'none' ],
			'detect.mailer'        => [ 'detect.mailer', 'none' ],
			'detect.analytics'     => [ 'detect.analytics', 'none' ],
			'detect.accessibility' => [ 'detect.accessibility', 'none' ],
		];
	}

	/**
	 * @dataProvider catalogue
	 *
	 * @param string $check_id Check id.
	 * @param string $expected Expected route.
	 */
	public function test_route_matches_the_catalogue( string $check_id, string $expected ): void {
		$this->assertSame( $expected, AlertRules::route( $this->row( $check_id ) ) );
	}

	public function test_every_catalogue_check_has_a_rule(): void {
		// The catalogue table has 32 rows (the plan's text says 31). Three have a per-finding rule, tested below.
		$expected = array_merge( array_keys( self::catalogue() ), [ 'vuln.match', 'security.admins', 'ssl.certificate' ] );
		$actual   = array_keys( AlertRules::table() );

		sort( $expected );
		sort( $actual );
		$this->assertSame( $expected, $actual );
	}

	public function test_vulnerability_high_and_critical_are_immediate_others_digest(): void {
		$this->assertSame( 'immediate', AlertRules::route( $this->row( 'vuln.match', 'critical' ) ) );
		$this->assertSame( 'immediate', AlertRules::route( $this->row( 'vuln.match', 'high' ) ) );
		$this->assertSame( 'digest', AlertRules::route( $this->row( 'vuln.match', 'medium' ) ) );
		$this->assertSame( 'digest', AlertRules::route( $this->row( 'vuln.match', 'low' ) ) );
	}

	public function test_certificate_is_immediate_at_fourteen_days_or_less(): void {
		$this->assertSame( 'immediate', AlertRules::route( $this->row( 'ssl.certificate', 'high', [ 'days_remaining' => 14 ] ) ) );
		$this->assertSame( 'immediate', AlertRules::route( $this->row( 'ssl.certificate', 'high', [ 'days_remaining' => 3 ] ) ) );
		$this->assertSame( 'digest', AlertRules::route( $this->row( 'ssl.certificate', 'medium', [ 'days_remaining' => 15 ] ) ) );
	}

	public function test_certificate_without_days_falls_back_to_severity(): void {
		$this->assertSame( 'immediate', AlertRules::route( $this->row( 'ssl.certificate', 'critical' ) ) );
		$this->assertSame( 'digest', AlertRules::route( $this->row( 'ssl.certificate', 'low' ) ) );
	}

	public function test_only_an_added_admin_is_immediate(): void {
		$this->assertSame( 'immediate', AlertRules::route( $this->row( 'security.admins', 'high', [ 'change' => 'added' ] ) ) );
		$this->assertSame( 'immediate', AlertRules::route( $this->row( 'security.admins', 'high' ) ) );
		$this->assertSame( 'digest', AlertRules::route( $this->row( 'security.admins', 'medium', [ 'change' => 'removed' ] ) ) );
	}

	public function test_accepted_and_muted_findings_never_route(): void {
		$this->assertSame( 'none', AlertRules::route( $this->row( 'errors.fatal', 'critical', [], 'accepted' ) ) );
		$this->assertSame( 'none', AlertRules::route( $this->row( 'errors.fatal', 'critical', [], 'muted' ) ) );
		$this->assertSame( 'none', AlertRules::route( $this->row( 'errors.fatal', 'critical', [], 'resolved' ) ) );
	}

	public function test_unknown_check_never_emails(): void {
		$this->assertSame( 'none', AlertRules::route( $this->row( 'made.up', 'critical' ) ) );
	}

	public function test_rules_are_filterable(): void {
		add_filter(
			'amc_alert_rules',
			static function ( array $rules ): array {
				$rules['made.up']      = 'digest';
				$rules['updates.core'] = 'none';
				$rules['perf.cache']   = static fn ( array $finding ): string => 'critical' === $finding['severity'] ? 'immediate' : 'none';

				return $rules;
			}
		);

		$this->assertSame( 'digest', AlertRules::route( $this->row( 'made.up' ) ) );
		$this->assertSame( 'none', AlertRules::route( $this->row( 'updates.core' ) ) );
		$this->assertSame( 'immediate', AlertRules::route( $this->row( 'perf.cache', 'critical' ) ) );
		$this->assertSame( 'none', AlertRules::route( $this->row( 'perf.cache', 'low' ) ) );
		$this->assertContains( 'made.up', AlertRules::alerting_checks() );
		$this->assertNotContains( 'updates.core', AlertRules::alerting_checks() );
	}

	public function test_alerting_checks_excludes_none_checks(): void {
		$ids = AlertRules::alerting_checks();

		$this->assertContains( 'errors.fatal', $ids );
		$this->assertContains( 'vuln.match', $ids );
		$this->assertNotContains( 'detect.cdn', $ids );
	}
}
