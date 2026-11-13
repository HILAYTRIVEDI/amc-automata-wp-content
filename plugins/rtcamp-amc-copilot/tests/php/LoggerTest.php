<?php
/**
 * Tests for the plugin Logger service.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests;

use AMC\Core\Logger;
use AMC\Helpers\Util;
use AMC\Main;
use rtCamp\WPPrimitives\Contracts\Interfaces\Shareable;
use rtCamp\WPPrimitives\Utils\Logger as FrameworkLogger;

/**
 * Class LoggerTest
 *
 * Runs against real WordPress (wp-env), where WP_DEBUG is true, so the plugin
 * logger writes. Output is captured by pointing PHP's error_log at a per-test
 * temp file (the same approach the framework LoggerTest uses), and assertions
 * match on the unique message each test logs so unrelated error_log noise from
 * the environment cannot make a test flaky.
 */
final class LoggerTest extends TestCase {

	/**
	 * Temp file capturing error_log() output for the current test.
	 *
	 * @var string
	 */
	private string $log_file;

	/**
	 * Original error_log ini value, restored in tear_down().
	 *
	 * @var string
	 */
	private string $original_error_log;

	/**
	 * Point PHP's error_log at a fresh temp file for the duration of the test.
	 */
	public function set_up(): void {
		parent::set_up();

		$tmp = tempnam( sys_get_temp_dir(), 'project-logger-' );
		$this->assertNotFalse( $tmp, 'Failed to create temp file for capturing error_log output.' );

		$this->log_file           = $tmp;
		$this->original_error_log = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $this->log_file );
	}

	/**
	 * Restore the original error_log destination and remove the temp file.
	 */
	public function tear_down(): void {
		ini_set( 'error_log', $this->original_error_log );

		if ( file_exists( $this->log_file ) ) {
			unlink( $this->log_file );
		}

		parent::tear_down();
	}

	/**
	 * Read whatever has been written to the captured error_log so far.
	 */
	private function captured(): string {
		return (string) file_get_contents( $this->log_file );
	}

	/**
	 * The class exists and extends the framework Logger.
	 */
	public function test_extends_framework_logger(): void {
		$this->assertInstanceOf( FrameworkLogger::class, new Logger() );
	}

	/**
	 * It is shareable, so the container hands out a single instance.
	 */
	public function test_is_shareable(): void {
		$this->assertInstanceOf( Shareable::class, new Logger() );
	}

	/**
	 * It is registered in Main and resolvable from the container.
	 */
	public function test_registered_and_shared_in_main(): void {
		$this->assertContains( Logger::class, Main::CLASSES );
		$this->assertInstanceOf( Logger::class, Main::get_instance()->get_shared( Logger::class ) );
	}

	/**
	 * The container instance and Util::logger() resolve to the SAME shared instance.
	 */
	public function test_util_logger_returns_the_shared_instance(): void {
		$from_container = Main::get_instance()->get_shared( Logger::class );

		$this->assertSame( $from_container, Util::logger() );
		$this->assertSame( Util::logger(), Util::logger() );
	}

	/**
	 * The level methods run and write the level label, the plugin prefix, the
	 * message, and JSON context, exactly like the framework logger under WP_DEBUG.
	 */
	public function test_levels_write_label_prefix_message_and_context(): void {
		$this->assertTrue( defined( 'WP_DEBUG' ) && WP_DEBUG, 'wp-env should run tests with WP_DEBUG enabled.' );

		$logger = Util::logger();

		$logger->debug( 'd-msg' );
		$logger->info( 'cache warmed', [ 'items' => 42 ] );
		$logger->warning( 'w-msg' );
		$logger->error( 'e-msg' );

		$contents = $this->captured();

		// The plugin logger is constructed with the 'amc' prefix.
		$this->assertStringContainsString( '[DEBUG] [amc] d-msg', $contents );
		$this->assertStringContainsString( '[INFO] [amc] cache warmed {"items":42}', $contents );
		$this->assertStringContainsString( '[WARNING] [amc] w-msg', $contents );
		$this->assertStringContainsString( '[ERROR] [amc] e-msg', $contents );
	}

	/**
	 * Logging without context omits the trailing JSON segment.
	 */
	public function test_no_context_omits_the_json_segment(): void {
		Util::logger()->info( 'plain-line-no-context' );

		$contents = $this->captured();
		$this->assertStringContainsString( '[INFO] [amc] plain-line-no-context', $contents );
		$this->assertStringNotContainsString( 'plain-line-no-context {', $contents );
	}
}
