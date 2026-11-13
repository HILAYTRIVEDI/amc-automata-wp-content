<?php
/**
 * Tests for the CLI module and the Healthcheck command.
 *
 * WP_CLI is generally not defined under the test runner, so run() is never
 * invoked here; the command contract is asserted via reflection and static
 * accessors only.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Tests;

use AMC_Automata_Features\Features\Modules\CLI;
use AMC_Automata_Features\Features\Modules\CLI\Healthcheck;
use ReflectionMethod;
use rtCamp\WPPrimitives\Contracts\Interfaces\CLICommand;

/**
 * Class CLITest
 */
final class CLITest extends TestCase {

	/**
	 * The command name surfaced to WP-CLI.
	 */
	private const COMMAND_NAME = 'health-check';

	/**
	 * Healthcheck honours the framework CLICommand contract.
	 */
	public function test_implements_cli_command_contract(): void {
		$this->assertInstanceOf( CLICommand::class, new Healthcheck() );
	}

	/**
	 * get_name() returns the exact, non-empty command name.
	 */
	public function test_get_name_returns_expected_command_name(): void {
		$name = Healthcheck::get_name();

		$this->assertIsString( $name );
		$this->assertNotEmpty( $name );
		$this->assertSame( self::COMMAND_NAME, $name );
	}

	/**
	 * get_description() returns a non-empty string.
	 */
	public function test_get_description_returns_non_empty_string(): void {
		$description = Healthcheck::get_description();

		$this->assertIsString( $description );
		$this->assertNotEmpty( $description );
	}

	/**
	 * run() exists as a static method with the documented signature.
	 *
	 * Asserted via reflection so WP_CLI:: calls inside the body never fire.
	 */
	public function test_run_has_expected_static_signature(): void {
		$method = new ReflectionMethod( Healthcheck::class, 'run' );

		$this->assertTrue( $method->isStatic() );
		$this->assertTrue( $method->isPublic() );
		$this->assertSame( 'void', (string) $method->getReturnType() );

		// Both arguments are optional (array $args = [], array $assoc_args = []).
		$this->assertSame( 2, $method->getNumberOfParameters() );
		$this->assertSame( 0, $method->getNumberOfRequiredParameters() );
	}

	/**
	 * The command class is one of the module's managed commands.
	 */
	public function test_command_is_in_module_get_commands(): void {
		$method = new ReflectionMethod( CLI::class, 'get_commands' );
		$method->setAccessible( true );

		$commands = $method->invoke( new CLI() );

		$this->assertArrayHasKey( self::COMMAND_NAME, $commands );
		$this->assertSame( [ Healthcheck::class, 'run' ], $commands[ self::COMMAND_NAME ]['callback'] );
	}

	/**
	 * Commands are added on init, so their translated descriptions are not
	 * requested before WordPress allows it.
	 */
	public function test_commands_are_added_on_init(): void {
		$cli = new CLI();
		$cli->register_hooks();

		$this->assertSame( 10, has_action( 'init', [ $cli, 'register_commands' ] ) );
	}

	/**
	 * The module skips registration when not running under WP-CLI.
	 */
	public function test_module_does_not_register_outside_wp_cli(): void {
		// WP_CLI is not defined under the PHPUnit runner.
		$this->assertFalse( ( new CLI() )->can_register() );
	}
}
