<?php
/**
 * Tests for the `wp amc report generate` command definition.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Reports;

use AMC\Reports\Cli\ReportGenerateCommand;
use AMC\Runner\Cli\CliModule;
use AMC\Tests\TestCase;

/**
 * Class ReportGenerateCommandTest
 *
 * WP-CLI is not loaded under PHPUnit, so this checks the registration and the
 * arguments; the work itself is the Generator, covered by GeneratorTest.
 */
final class ReportGenerateCommandTest extends TestCase {

	public function test_the_command_is_registered_under_amc_report_generate(): void {
		$this->assertSame( 'report generate', ReportGenerateCommand::get_name() );
		$this->assertSame( ReportGenerateCommand::class, CliModule::commands()['report generate'] );
	}

	public function test_synopsis_has_period_start_and_end(): void {
		$synopsis = array_column( ReportGenerateCommand::get_synopsis(), null, 'name' );

		$this->assertSame( [ 'month', 'quarter', 'year', 'custom' ], $synopsis['period']['options'] );
		$this->assertSame( 'month', $synopsis['period']['default'] );
		$this->assertTrue( $synopsis['start']['optional'] );
		$this->assertTrue( $synopsis['end']['optional'] );
		$this->assertSame( 'assoc', $synopsis['period']['type'] );
	}
}
