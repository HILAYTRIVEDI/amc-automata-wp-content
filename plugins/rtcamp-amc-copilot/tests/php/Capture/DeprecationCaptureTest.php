<?php
/**
 * Tests for deprecation capture and caller attribution.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Capture;

use AMC\Capture\Buffer;
use AMC\Capture\DeprecationCapture;
use AMC\Storage\EventsRepository;
use AMC\Tests\TestCase;

/**
 * Class DeprecationCaptureTest
 */
final class DeprecationCaptureTest extends TestCase {

	public function test_all_six_hooks_are_registered(): void {
		$capture = new DeprecationCapture( new Buffer() );
		$capture->register_hooks();

		foreach ( [ 'deprecated_function_run', 'deprecated_argument_run', 'deprecated_hook_run', 'deprecated_file_included', 'deprecated_class_run', 'doing_it_wrong_run' ] as $hook ) {
			$this->assertNotFalse(
				has_action(
					$hook,
					[
						$capture,
						'on_' . ( [
							'deprecated_function_run'  => 'function',
							'deprecated_argument_run'  => 'argument',
							'deprecated_hook_run'      => 'hook',
							'deprecated_file_included' => 'file',
							'deprecated_class_run'     => 'class',
							'doing_it_wrong_run'       => 'doing_it_wrong',
						][ $hook ] ),
					]
				),
				$hook
			);
		}
	}

	public function test_each_hook_buffers_one_event_with_the_right_type(): void {
		$buffer  = new Buffer();
		$capture = new DeprecationCapture( $buffer );

		$capture->on_function( 'old_fn', 'new_fn', '6.0.0' );
		$capture->on_argument( 'some_fn', 'The $x argument is deprecated.', '5.0.0' );
		$capture->on_hook( 'old_hook', 'new_hook', '6.1.0', 'Please update.' );
		$capture->on_file( 'old-file.php', 'new-file.php', '4.0.0', '' );
		$capture->on_class( 'Old_Class', 'New_Class', '6.2.0' );
		$capture->on_doing_it_wrong( 'add_thing', 'Do not do that.', '6.3.0' );

		$this->assertSame( 6, $buffer->size() );
	}

	public function test_real_deprecated_call_is_grouped_by_the_calling_file_and_line(): void {
		$buffer  = new Buffer();
		$capture = new DeprecationCapture( $buffer );
		$capture->register_hooks();
		$this->setExpectedDeprecated( 'amc_legacy_api' );

		$line = __LINE__ + 1;
		foreach ( [ 1, 2 ] as $unused ) { $this->amc_legacy_api(); } // phpcs:ignore -- One line on purpose: the line is part of the group.
		$this->amc_legacy_api();
		$buffer->drain();

		$rows = ( new EventsRepository() )->grouped( [ 'type' => 'deprecation' ] );
		$mine = array_values( array_filter( $rows, static fn ( array $row ): bool => str_ends_with( $row['file'], 'DeprecationCaptureTest.php' ) && $row['line'] === $line ) );

		$this->assertCount( 1, $mine, 'The caller, not WordPress, is the group' );
		$this->assertSame( 2, $mine[0]['count'], 'Two calls from the same line; a third from another line is a different group' );
		$this->assertStringContainsString( 'amc_legacy_api', $mine[0]['message'] );
	}

	public function test_doing_it_wrong_is_captured_from_the_real_hook(): void {
		$buffer  = new Buffer();
		$capture = new DeprecationCapture( $buffer );
		$capture->register_hooks();
		$this->setExpectedIncorrectUsage( 'amc_wrong_usage' );

		_doing_it_wrong( 'amc_wrong_usage', 'Use it differently.', '6.0.0' );

		$this->assertSame( 1, $buffer->size() );
		$buffer->drain();
		$this->assertNotEmpty( array_filter( ( new EventsRepository() )->grouped( [ 'type' => 'doing_it_wrong' ] ), static fn ( array $row ): bool => str_contains( $row['message'], 'amc_wrong_usage: Use it differently.' ) ) );
	}

	public function test_caller_skips_wordpress_internals_after_the_trigger(): void {
		$frames = [
			[
				'function' => 'on_function',
				'file'     => '/srv/wp-includes/class-wp-hook.php',
				'line'     => 326,
			],
			[
				'function' => 'do_action',
				'file'     => '/srv/wp-includes/functions.php',
				'line'     => 1,
			],
			[
				'function' => '_deprecated_function',
				'file'     => '/srv/wp-includes/deprecated.php',
				'line'     => 10,
			],
			[
				'function' => 'old_core_fn',
				'file'     => '/srv/wp-includes/other.php',
				'line'     => 20,
			],
			[
				'function' => 'my_plugin_fn',
				'file'     => '/srv/wp-content/plugins/foo/foo.php',
				'line'     => 42,
			],
			[
				'function' => 'require',
				'file'     => '/srv/wp-settings.php',
				'line'     => 5,
			],
		];

		$this->assertSame( [ '/srv/wp-content/plugins/foo/foo.php', 42 ], DeprecationCapture::caller( $frames ) );
	}

	public function test_caller_falls_back_to_the_first_frame_after_the_trigger_when_only_core_is_involved(): void {
		$frames = [
			[
				'function' => '_deprecated_function',
				'file'     => '/srv/wp-includes/deprecated.php',
				'line'     => 10,
			],
			[
				'function' => 'old_core_fn',
				'file'     => '/srv/wp-admin/admin.php',
				'line'     => 20,
			],
		];

		$this->assertSame( [ '/srv/wp-admin/admin.php', 20 ], DeprecationCapture::caller( $frames ) );
		$this->assertSame( [ '', 0 ], DeprecationCapture::caller( [] ) );
	}

	public function test_a_full_buffer_skips_the_backtrace_and_counts_the_drop(): void {
		$buffer = new Buffer();
		for ( $i = 0; $i < Buffer::MAX_ENTRIES; $i++ ) {
			$buffer->add_event( 'notice', 'm', '/f.php', $i );
		}

		( new DeprecationCapture( $buffer ) )->on_function( 'old_fn', '', '6.0.0' );

		$this->assertSame( Buffer::MAX_ENTRIES, $buffer->size() );
		$this->assertSame( 1, $buffer->dropped() );
	}

	public function test_hooks_run_no_queries(): void {
		global $wpdb;
		$capture = new DeprecationCapture( new Buffer() );
		$before  = $wpdb->num_queries;

		$capture->on_function( 'old_fn', 'new_fn', '6.0.0' );
		$capture->on_doing_it_wrong( 'f', 'm', '6.0.0' );

		$this->assertSame( $before, $wpdb->num_queries );
	}

	/**
	 * A "deprecated" function as a plugin would define one.
	 */
	private function amc_legacy_api(): void {
		_deprecated_function( 'amc_legacy_api', '1.0.0', 'amc_new_api' );
	}
}
