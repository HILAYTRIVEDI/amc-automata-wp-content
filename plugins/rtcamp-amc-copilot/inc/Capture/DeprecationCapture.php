<?php
/**
 * WordPress deprecation and incorrect-usage capture.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Capture;

use rtCamp\WPPrimitives\Contracts\Interfaces\Registrable;

/**
 * Class DeprecationCapture
 *
 * Listens to WordPress's `*_run` hooks. Each occurrence is grouped by the
 * place that called the deprecated API: a `debug_backtrace()` limited to
 * BACKTRACE_LIMIT frames, without arguments, walks past the WordPress
 * internals to the first frame outside wp-includes/wp-admin.
 *
 * The signature is `md5( type | caller file | caller line | message )`, so the
 * same deprecated function called from two plugins is two rows.
 *
 * When the buffer is full the backtrace is skipped altogether.
 *
 * @since 0.1.0
 */
final class DeprecationCapture implements Registrable {

	/**
	 * Frames inspected for the caller.
	 */
	public const BACKTRACE_LIMIT = 10;

	/**
	 * Constructor.
	 *
	 * @param Buffer|null $buffer Buffer (default: the request-wide one).
	 */
	public function __construct( private ?Buffer $buffer = null ) {
		$this->buffer ??= Buffer::shared();
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		if ( Buffer::disabled_by_constant() ) {
			return;
		}

		add_action( 'deprecated_function_run', [ $this, 'on_function' ], 10, 3 );
		add_action( 'deprecated_argument_run', [ $this, 'on_argument' ], 10, 3 );
		add_action( 'deprecated_hook_run', [ $this, 'on_hook' ], 10, 4 );
		add_action( 'deprecated_file_included', [ $this, 'on_file' ], 10, 4 );
		add_action( 'deprecated_class_run', [ $this, 'on_class' ], 10, 3 );
		add_action( 'doing_it_wrong_run', [ $this, 'on_doing_it_wrong' ], 10, 3 );

		$this->buffer->register_flush();
	}

	/**
	 * `deprecated_function_run`.
	 *
	 * @param string $function_name Deprecated function.
	 * @param string $replacement   Replacement.
	 * @param string $version       Version it was deprecated in.
	 */
	public function on_function( string $function_name, string $replacement, string $version ): void {
		$this->record( 'deprecation', self::deprecated( 'Function', $function_name, $version, $replacement ) );
	}

	/**
	 * `deprecated_argument_run`.
	 *
	 * @param string $function_name Function.
	 * @param string $message       Message.
	 * @param string $version       Version.
	 */
	public function on_argument( string $function_name, string $message, string $version ): void {
		$this->record( 'deprecation', sprintf( 'Argument of %s is deprecated since version %s. %s', $function_name, $version, $message ) );
	}

	/**
	 * `deprecated_hook_run`.
	 *
	 * @param string $hook        Hook.
	 * @param string $replacement Replacement.
	 * @param string $version     Version.
	 * @param string $message     Message.
	 */
	public function on_hook( string $hook, string $replacement, string $version, string $message = '' ): void {
		$this->record( 'deprecation', trim( self::deprecated( 'Hook', $hook, $version, $replacement ) . ' ' . $message ) );
	}

	/**
	 * `deprecated_file_included`.
	 *
	 * @param string $file        File.
	 * @param string $replacement Replacement.
	 * @param string $version     Version.
	 * @param string $message     Message.
	 */
	public function on_file( string $file, string $replacement, string $version, string $message = '' ): void {
		$this->record( 'deprecation', trim( self::deprecated( 'File', $file, $version, $replacement ) . ' ' . $message ) );
	}

	/**
	 * `deprecated_class_run`.
	 *
	 * @param string $class_name  Class.
	 * @param string $replacement Replacement.
	 * @param string $version     Version.
	 */
	public function on_class( string $class_name, string $replacement, string $version ): void {
		$this->record( 'deprecation', self::deprecated( 'Class', $class_name, $version, $replacement ) );
	}

	/**
	 * `doing_it_wrong_run`.
	 *
	 * @param string $function_name Function.
	 * @param string $message       Message.
	 * @param string $version       Version.
	 */
	public function on_doing_it_wrong( string $function_name, string $message, string $version ): void {
		unset( $version );
		$this->record( 'doing_it_wrong', $function_name . ': ' . $message );
	}

	/**
	 * Find the caller and buffer the event.
	 *
	 * @param string $type    Event type.
	 * @param string $message Message.
	 */
	private function record( string $type, string $message ): void {
		if ( $this->buffer->is_full() ) {
			$this->buffer->note_dropped();
			return;
		}

		[ $file, $line ] = self::caller( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, self::BACKTRACE_LIMIT ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Caller attribution; bounded and argument-free.

		$this->buffer->add_event( $type, $message, $file, $line );
	}

	/**
	 * First frame outside WordPress core after the deprecation trigger.
	 *
	 * @param list<array<string, mixed>> $frames debug_backtrace() frames.
	 *
	 * @return array{0: string, 1: int} File and line.
	 */
	public static function caller( array $frames ): array {
		$start = 0;
		foreach ( $frames as $index => $frame ) {
			$function = (string) ( $frame['function'] ?? '' );
			if ( str_starts_with( $function, '_deprecated_' ) || '_doing_it_wrong' === $function || str_ends_with( $function, '_deprecated' ) ) {
				$start = $index + 1;
				break;
			}
		}

		$fallback = $frames[ $start ] ?? $frames[ count( $frames ) - 1 ] ?? [];

		for ( $i = $start, $total = count( $frames ); $i < $total; $i++ ) {
			$file = (string) ( $frames[ $i ]['file'] ?? '' );
			if ( '' !== $file && ! self::is_core_file( $file ) ) {
				return [ $file, (int) ( $frames[ $i ]['line'] ?? 0 ) ];
			}
		}

		return [ (string) ( $fallback['file'] ?? '' ), (int) ( $fallback['line'] ?? 0 ) ];
	}

	/**
	 * Whether a file belongs to the WordPress internals that sit between a
	 * deprecated call and its caller.
	 *
	 * @param string $file Path.
	 */
	private static function is_core_file( string $file ): bool {
		$file = str_replace( '\\', '/', $file );

		return str_contains( $file, '/wp-includes/' ) || str_contains( $file, '/wp-admin/' );
	}

	/**
	 * Standard sentence for a deprecated thing.
	 *
	 * @param string $kind        Function, Hook, File or Class.
	 * @param string $name        Name.
	 * @param string $version     Version.
	 * @param string $replacement Replacement ('' = none).
	 */
	private static function deprecated( string $kind, string $name, string $version, string $replacement ): string {
		return '' !== $replacement
			? sprintf( '%s %s is deprecated since version %s. Use %s instead.', $kind, $name, $version, $replacement )
			: sprintf( '%s %s is deprecated since version %s with no alternative available.', $kind, $name, $version );
	}
}
