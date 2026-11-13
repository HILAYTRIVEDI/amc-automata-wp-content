<?php
/**
 * Tests for PHP error capture and handler chaining.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Capture;

use AMC\Capture\Buffer;
use AMC\Capture\ErrorCapture;
use AMC\Storage\EventsRepository;
use AMC\Tests\TestCase;

/**
 * Class ErrorCaptureTest
 */
final class ErrorCaptureTest extends TestCase {

	/**
	 * Handlers installed by a test, popped in tear_down.
	 *
	 * @var int
	 */
	private int $installed = 0;

	public function tear_down(): void {
		while ( $this->installed > 0 ) {
			restore_error_handler();
			--$this->installed;
		}

		parent::tear_down();
	}

	/**
	 * Install a "previous" handler, then ours on top.
	 *
	 * @param Buffer        $buffer   Buffer.
	 * @param \Closure|null $previous Previous handler (null = none).
	 */
	private function capture( Buffer $buffer, ?\Closure $previous ): ErrorCapture {
		if ( null !== $previous ) {
			set_error_handler( $previous ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
			++$this->installed;
		}

		$capture = new ErrorCapture( $buffer, false );
		$capture->register_hooks();
		++$this->installed;

		return $capture;
	}

	public function test_previous_handler_returning_false_is_chained_and_its_result_returned(): void {
		$calls  = [];
		$buffer = new Buffer();
		$handle = $this->capture(
			$buffer,
			static function ( int $errno, string $errstr, string $errfile, int $errline ) use ( &$calls ): bool {
				$calls[] = [ $errno, $errstr, $errfile, $errline ];
				return false;
			}
		);

		$this->assertFalse( $handle->handle( E_WARNING, 'boom', '/a.php', 4 ) );
		$this->assertSame( [ [ E_WARNING, 'boom', '/a.php', 4 ] ], $calls );
		$this->assertSame( 1, $buffer->size() );
	}

	public function test_previous_handler_returning_true_is_chained_and_its_result_returned(): void {
		$buffer = new Buffer();
		$calls  = 0;
		$handle = $this->capture(
			$buffer,
			static function () use ( &$calls ): bool {
				++$calls;
				return true;
			}
		);

		$this->assertTrue( $handle->handle( E_NOTICE, 'n', '/a.php', 1 ) );
		$this->assertSame( 1, $calls );
		$this->assertSame( 1, $buffer->size() );
	}

	public function test_previous_handler_without_a_return_value_counts_as_handled(): void {
		$handle = $this->capture( new Buffer(), static function (): void {} );

		$this->assertTrue( $handle->handle( E_WARNING, 'w', '/a.php', 1 ) );
	}

	public function test_without_a_previous_handler_php_continues_with_its_own(): void {
		$previous = set_error_handler( null ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Start from no handler.
		$buffer   = new Buffer();
		$capture  = new ErrorCapture( $buffer, false );
		$capture->register_hooks();

		$this->assertFalse( $capture->handle( E_WARNING, 'w', '/a.php', 1 ) );
		$this->assertSame( 1, $buffer->size() );

		restore_error_handler();
		restore_error_handler();
		if ( null !== $previous ) {
			set_error_handler( $previous ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
			++$this->installed;
		}
	}

	public function test_previous_handler_runs_even_when_the_error_is_not_recorded(): void {
		$calls  = 0;
		$buffer = new Buffer();
		$handle = $this->capture(
			$buffer,
			static function () use ( &$calls ): bool {
				++$calls;
				return false;
			}
		);

		$handle->handle( 99999, 'unknown level', '/a.php', 1 );
		$handle->handle( E_USER_DEPRECATED, 'x', ABSPATH . 'wp-includes/functions.php', 5000 );

		$this->assertSame( 2, $calls );
		$this->assertSame( 0, $buffer->size() );
	}

	/**
	 * @dataProvider levels
	 *
	 * @param int    $level PHP level.
	 * @param string $type  Event type.
	 */
	public function test_php_levels_map_to_event_types( int $level, string $type ): void {
		$buffer = new Buffer();
		$handle = $this->capture( $buffer, null );

		$handle->handle( $level, 'message', WP_PLUGIN_DIR . '/lvl/lvl.php', $level );
		$buffer->drain();

		$rows = array_values(
			array_filter(
				( new EventsRepository() )->grouped( [ 'type' => $type ] ),
				static fn ( array $row ): bool => 'plugin:lvl' === $row['source'] && $level === $row['line']
			)
		);
		$this->assertNotEmpty( $rows, "Level {$level} should be stored as {$type}" );
	}

	/**
	 * Level, type.
	 *
	 * @return array<string, array{0: int, 1: string}>
	 */
	public static function levels(): array {
		return [
			'warning'         => [ E_WARNING, 'warning' ],
			'user warning'    => [ E_USER_WARNING, 'warning' ],
			'notice'          => [ E_NOTICE, 'notice' ],
			'user notice'     => [ E_USER_NOTICE, 'notice' ],
			'deprecated'      => [ E_DEPRECATED, 'deprecation' ],
			'user deprecated' => [ E_USER_DEPRECATED, 'deprecation' ],
			'user error'      => [ E_USER_ERROR, 'fatal' ],
		];
	}

	public function test_errors_silenced_with_the_at_operator_are_ignored(): void {
		$buffer  = new Buffer();
		$capture = $this->capture( $buffer, null );

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Testing the @ operator.
		@$capture->handle( E_WARNING, 'silenced', '/a.php', 1 );

		$this->assertSame( 0, $buffer->size() );
	}

	public function test_wordpress_deprecation_triggers_are_left_to_deprecation_capture(): void {
		$buffer  = new Buffer();
		$capture = $this->capture( $buffer, null );

		$capture->handle( E_USER_DEPRECATED, 'Function x is deprecated', ABSPATH . 'wp-includes/functions.php', 6000 );
		$capture->handle( E_USER_NOTICE, 'Function y was called incorrectly', ABSPATH . 'wp-includes/functions.php', 6001 );
		$capture->handle( E_USER_DEPRECATED, 'A plugin deprecates itself', WP_PLUGIN_DIR . '/p/p.php', 3 );

		$this->assertSame( 1, $buffer->size() );
	}

	public function test_handler_runs_no_queries(): void {
		global $wpdb;
		$handle = $this->capture( new Buffer(), null );
		$before = $wpdb->num_queries;

		for ( $i = 0; $i < 50; $i++ ) {
			$handle->handle( E_WARNING, "w{$i}", '/a.php', $i );
		}

		$this->assertSame( $before, $wpdb->num_queries );
	}

	public function test_wordpress_fatal_screen_records_and_flushes_once(): void {
		$buffer  = new Buffer();
		$capture = new ErrorCapture( $buffer, false );
		$error   = [
			'type'    => E_ERROR,
			'message' => 'Uncaught Error: Call to undefined function amc_missing_fn() in ' . WP_PLUGIN_DIR . '/fatal-p/fatal.php:8',
			'file'    => WP_PLUGIN_DIR . '/fatal-p/fatal.php',
			'line'    => 8,
		];

		$this->assertSame( 'screen', $capture->on_fatal_screen( 'screen', $error ) );
		$capture->on_fatal_screen( 'screen', $error );
		$capture->on_shutdown( $error );

		$rows = array_values( array_filter( ( new EventsRepository() )->grouped( [ 'type' => 'fatal' ] ), static fn ( array $row ): bool => 'plugin:fatal-p' === $row['source'] ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 1, $rows[0]['count'], 'Counted once, not once per path' );
		$this->assertSame( 0, $buffer->size(), 'Flushed straight away' );
	}

	public function test_shutdown_records_a_fatal_but_not_a_warning(): void {
		$buffer  = new Buffer();
		$capture = new ErrorCapture( $buffer, false );

		$capture->on_shutdown(
			[
				'type'    => E_WARNING,
				'message' => 'not fatal',
				'file'    => '/a.php',
				'line'    => 1,
			]
		);
		$this->assertSame( 0, $buffer->size() );

		$capture->on_shutdown(
			[
				'type'    => E_PARSE,
				'message' => 'syntax error, unexpected token "}"',
				'file'    => WP_PLUGIN_DIR . '/parse-p/parse.php',
				'line'    => 2,
			]
		);
		$this->assertCount( 1, array_filter( ( new EventsRepository() )->grouped( [ 'type' => 'fatal' ] ), static fn ( array $row ): bool => 'plugin:parse-p' === $row['source'] ) );
	}

	public function test_registers_its_hooks_and_a_flush(): void {
		$buffer  = new Buffer();
		$capture = $this->capture( $buffer, null );

		$this->assertSame( 10, has_filter( 'wp_php_error_message', [ $capture, 'on_fatal_screen' ] ) );
		$this->assertSame( 999, has_action( 'shutdown', [ $buffer, 'flush' ] ) );
	}
}
