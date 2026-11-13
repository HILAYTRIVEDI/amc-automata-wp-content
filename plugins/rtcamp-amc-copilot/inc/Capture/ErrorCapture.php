<?php
/**
 * PHP error and fatal capture.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Capture;

use rtCamp\WPPrimitives\Contracts\Interfaces\Registrable;

/**
 * Class ErrorCapture
 *
 * - `set_error_handler()`: records warnings, notices, deprecations and
 *   `E_USER_ERROR` in the buffer, then ALWAYS chains to the previous handler
 *   and returns its result (a handler that returns `false` still lets PHP's
 *   own handler run; one that returns `true` still marks the error handled).
 *   Errors silenced with `@` are ignored.
 * - Fatals: a `register_shutdown_function()` reads `error_get_last()`. When
 *   WordPress shows its fatal-error screen (recovery mode) it exits inside its
 *   own shutdown function, so our shutdown function never runs; the
 *   `wp_php_error_message` filter catches that case and flushes immediately.
 * - WordPress's own `_deprecated_*` / `_doing_it_wrong` notices are skipped
 *   here (DeprecationCapture records them from the hooks, with a better caller).
 *
 * Cost per request: one `set_error_handler()`, one filter, one shutdown
 * function. No queries, no options.
 *
 * @since 0.1.0
 */
final class ErrorCapture implements Registrable {

	/**
	 * PHP error level => event type.
	 */
	public const TYPES = [
		E_ERROR             => 'fatal',
		E_CORE_ERROR        => 'fatal',
		E_COMPILE_ERROR     => 'fatal',
		E_USER_ERROR        => 'fatal',
		E_RECOVERABLE_ERROR => 'fatal',
		E_PARSE             => 'fatal',
		E_WARNING           => 'warning',
		E_CORE_WARNING      => 'warning',
		E_COMPILE_WARNING   => 'warning',
		E_USER_WARNING      => 'warning',
		E_NOTICE            => 'notice',
		E_USER_NOTICE       => 'notice',
		E_DEPRECATED        => 'deprecation',
		E_USER_DEPRECATED   => 'deprecation',
	];

	/**
	 * Levels PHP never lets the user handler see. Also what `error_reporting()`
	 * is left with when an error is silenced with `@` (PHP 8).
	 */
	private const FATAL_LEVELS = E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR | E_PARSE;

	/**
	 * Levels only visible through error_get_last() in shutdown.
	 */
	private const SHUTDOWN_LEVELS = E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_PARSE;

	/**
	 * Handler that was installed before ours.
	 *
	 * @var mixed
	 */
	private mixed $previous = null;

	/**
	 * Whether a fatal was already recorded this request.
	 *
	 * @var bool
	 */
	private bool $fatal_recorded = false;

	/**
	 * Constructor.
	 *
	 * @param Buffer|null $buffer            Buffer (default: the request-wide one).
	 * @param bool        $register_shutdown Register the fatal shutdown function (off in benchmarks).
	 */
	public function __construct(
		private ?Buffer $buffer = null,
		private readonly bool $register_shutdown = true
	) {
		$this->buffer ??= Buffer::shared();
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		if ( Buffer::disabled_by_constant() ) {
			return;
		}

		$this->previous = set_error_handler( [ $this, 'handle' ] ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Capturing PHP errors is the purpose; the previous handler is always chained.
		add_filter( 'wp_php_error_message', [ $this, 'on_fatal_screen' ], 10, 2 );

		if ( $this->register_shutdown ) {
			register_shutdown_function( [ $this, 'on_shutdown' ] );
		}

		$this->buffer->register_flush();
	}

	/**
	 * PHP error handler.
	 *
	 * @param int    $errno   Level.
	 * @param string $errstr  Message.
	 * @param string $errfile File.
	 * @param int    $errline Line.
	 *
	 * @return bool What the previous handler returned; false when there was none.
	 */
	public function handle( int $errno, string $errstr, string $errfile = '', int $errline = 0 ): bool {
		$type = self::TYPES[ $errno ] ?? null;

		if ( null !== $type && ! self::is_silenced( $errno ) && ! self::is_wordpress_trigger( $errno, $errfile ) ) {
			$this->buffer->add_event( $type, $errstr, $errfile, $errline );
		}

		if ( null === $this->previous || ! is_callable( $this->previous ) ) {
			return false;
		}

		return false !== ( $this->previous )( $errno, $errstr, $errfile, $errline );
	}

	/**
	 * WordPress's fatal-error screen (recovery mode) is about to be shown and
	 * the process is about to exit: record and flush now.
	 *
	 * @param mixed $message Error screen message (returned untouched).
	 * @param mixed $error   Error array from error_get_last().
	 */
	public function on_fatal_screen( mixed $message, mixed $error = null ): mixed {
		if ( is_array( $error ) ) {
			$this->record_fatal( $error );
		}

		return $message;
	}

	/**
	 * Shutdown function: catches fatals when WordPress did not.
	 *
	 * @param array<mixed>|null $last_error error_get_last() shape (test seam; default: the real one).
	 */
	public function on_shutdown( ?array $last_error = null ): void {
		$error = $last_error ?? error_get_last();

		if ( null !== $error && 0 !== ( $error['type'] & self::SHUTDOWN_LEVELS ) ) {
			$this->record_fatal( $error );
		}
	}

	/**
	 * Record a fatal once and flush straight away.
	 *
	 * @param array<mixed> $error error_get_last() shape.
	 */
	private function record_fatal( array $error ): void {
		if ( $this->fatal_recorded || ! isset( $error['type'] ) || 0 === ( (int) $error['type'] & self::FATAL_LEVELS ) ) {
			return;
		}

		$this->fatal_recorded = true;
		$this->buffer->add_event( 'fatal', (string) ( $error['message'] ?? '' ), (string) ( $error['file'] ?? '' ), (int) ( $error['line'] ?? 0 ) );
		$this->buffer->drain();
	}

	/**
	 * Whether the error was silenced with `@` (or only fatal levels are reported).
	 *
	 * @param int $errno Level.
	 */
	private static function is_silenced( int $errno ): bool {
		if ( 0 !== ( $errno & self::FATAL_LEVELS ) ) {
			return false;
		}

		return 0 === ( error_reporting() & ~self::FATAL_LEVELS ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- Read only: detects the @ operator.
	}

	/**
	 * Notices that `_deprecated_*()` and `_doing_it_wrong()` raise through
	 * wp-includes/functions.php; DeprecationCapture already has them.
	 *
	 * @param int    $errno Level.
	 * @param string $file  File.
	 */
	private static function is_wordpress_trigger( int $errno, string $file ): bool {
		return ( E_USER_DEPRECATED === $errno || E_USER_NOTICE === $errno )
			&& str_ends_with( str_replace( '\\', '/', $file ), 'wp-includes/functions.php' );
	}
}
