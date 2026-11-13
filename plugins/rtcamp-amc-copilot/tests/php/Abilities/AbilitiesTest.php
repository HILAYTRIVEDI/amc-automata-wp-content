<?php
/**
 * Tests for the Abilities API registration.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Abilities;

use AMC\Abilities\AbilitiesRegistrar;
use AMC\Abilities\CheckAbility;
use AMC\Checks\Finding;
use AMC\Checks\Registry;
use AMC\Checks\Result;
use AMC\Checks\Severity;
use AMC\Core\Capabilities;
use AMC\Runner\Runner;
use AMC\Tests\Support\FakeCheck;
use AMC\Tests\Support\ReplacesChecks;
use AMC\Tests\TestCase;

/**
 * Class AbilitiesTest
 */
final class AbilitiesTest extends TestCase {

	use ReplacesChecks;

	/**
	 * The abilities the registrar would register, keyed by name.
	 *
	 * @return array<string, \rtCamp\WPPrimitives\Contracts\Abstracts\AbstractAbility>
	 */
	private function abilities(): array {
		$out = [];
		foreach ( ( new AbilitiesRegistrar() )->list_abilities() as $ability ) {
			$out[ $ability->name() ] = $ability;
		}

		return $out;
	}

	public function test_names_follow_the_abilities_api_pattern(): void {
		$this->assertSame( 'amc-copilot/updates-core', CheckAbility::name_for( 'updates.core' ) );
		$this->assertSame( 'amc-copilot/perf-slow-queries', CheckAbility::name_for( 'perf.slow_queries' ) );

		foreach ( array_keys( $this->abilities() ) as $name ) {
			$this->assertMatchesRegularExpression( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $name );
		}
	}

	public function test_every_registered_check_has_an_ability_plus_the_report_reads(): void {
		$abilities = $this->abilities();

		foreach ( array_keys( ( new Registry() )->all() ) as $check_id ) {
			$this->assertArrayHasKey( CheckAbility::name_for( $check_id ), $abilities, $check_id );
		}

		$this->assertArrayHasKey( 'amc-copilot/reports', $abilities );
		$this->assertArrayHasKey( 'amc-copilot/report-snapshot', $abilities );
		$this->assertCount( count( ( new Registry() )->all() ) + 2, $abilities );
		$this->assertArrayHasKey( 'amc-copilot/vuln-match', $abilities );
		$this->assertArrayHasKey( 'amc-copilot/ssl-certificate', $abilities );
	}

	public function test_every_ability_is_annotated_read_only_and_gated_on_amc_manage(): void {
		foreach ( $this->abilities() as $name => $ability ) {
			$args = $ability->args();

			$this->assertTrue( $args['meta']['annotations']['readonly'], $name );
			$this->assertFalse( $args['meta']['annotations']['destructive'], $name );
			$this->assertNotSame( '', $args['label'], $name );
			$this->assertNotSame( '', $args['description'], $name );
			$this->assertSame( 'amc-copilot', $args['category'], $name );
			$this->assertFalse( ( $args['permission_callback'] )(), $name . ': anonymous is denied' );
		}
	}

	public function test_engineers_may_execute_and_other_administrators_may_not(): void {
		$ability = $this->abilities()['amc-copilot/vuln-match']->args();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertFalse( ( $ability['permission_callback'] )() );

		$engineer = self::factory()->user->create( [ 'role' => 'administrator' ] );
		Capabilities::grant( $engineer );
		wp_set_current_user( $engineer );
		$this->assertTrue( ( $ability['permission_callback'] )() );
	}

	public function test_a_check_ability_returns_the_latest_run_and_open_findings_without_running_the_check(): void {
		$check = new FakeCheck( 'zz.ability', Result::warn( 'Careful', [ 'x' => 1 ], [ new Finding( 'k1', Severity::HIGH, 'Problem' ) ] ) );
		$this->replace_checks( $check );

		$ability = new CheckAbility( $check );

		$before = $ability->execute( null );
		$this->assertNull( $before['run'] );
		$this->assertSame( [], $before['findings'] );
		$this->assertCount( 0, $check->contexts, 'Executing the ability never runs the check' );

		( new Runner() )->run( 'zz.ability' );

		$after = $ability->execute( null );
		$this->assertSame( 'zz.ability', $after['check']['id'] );
		$this->assertSame( 'warn', $after['run']['status'] );
		$this->assertSame( [ 'x' => 1 ], $after['run']['data'] );
		$this->assertCount( 1, $after['findings'] );
		$this->assertSame( 'k1', $after['findings'][0]['dedupe_key'] );
		$this->assertCount( 1, $check->contexts );
	}

	public function test_abilities_are_registered_with_wordpress_when_the_api_exists(): void {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'The Abilities API needs WordPress 6.9+.' );
		}

		$this->assertTrue( wp_has_ability_category( 'amc-copilot' ) );

		$ability = wp_get_ability( 'amc-copilot/updates-core' );
		$this->assertNotNull( $ability );
		$this->assertTrue( $ability->get_meta()['annotations']['readonly'] );
	}

	public function test_report_abilities_go_through_the_rest_routes_and_respect_permissions(): void {
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$engineer = self::factory()->user->create( [ 'role' => 'administrator' ] );
		Capabilities::grant( $engineer );
		wp_set_current_user( $engineer );

		$list = $this->abilities()['amc-copilot/reports']->execute( [ 'status' => 'any' ] );
		$this->assertIsArray( $list );

		$missing = $this->abilities()['amc-copilot/report-snapshot']->execute( [ 'id' => 999999 ] );
		$this->assertInstanceOf( \WP_Error::class, $missing );

		$wp_rest_server = null;
	}
}
