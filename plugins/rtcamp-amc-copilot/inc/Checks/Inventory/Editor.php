<?php
/**
 * Check: inventory.editor.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks\Inventory;

use AMC\Checks\Check;
use AMC\Checks\Context;
use AMC\Checks\Group;
use AMC\Checks\Result;
use AMC\Checks\Schedule;
use AMC\Support\AdminFunctions;

/**
 * Class Editor
 *
 * Reports whether the site uses the block editor or the Classic Editor plugin,
 * per public post type.
 *
 * Result::$data shape:
 *   classic_editor       bool Whether the Classic Editor plugin is installed.
 *   classic_editor_active bool Whether it is active.
 *   post_types           array<string, bool> Post type => block editor enabled.
 *   editor               'block'|'classic'|'mixed'
 *
 * Status: PASS.
 *
 * @since 0.1.0
 */
final class Editor implements Check {

	/**
	 * Returns the installed plugins, keyed by file.
	 *
	 * @var callable(): array<string, array<string, mixed>>
	 */
	private $plugins_source;

	/**
	 * Returns the active plugin files.
	 *
	 * @var callable(): list<string>
	 */
	private $active_source;

	/**
	 * Returns the block-editor state per public post type.
	 *
	 * @var callable(): array<string, bool>
	 */
	private $editor_source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $plugins_source Test seam; default get_plugins().
	 * @param callable|null $active_source  Test seam; default get_option( 'active_plugins' ).
	 * @param callable|null $editor_source  Test seam; default use_block_editor_for_post_type() per public type.
	 */
	public function __construct( ?callable $plugins_source = null, ?callable $active_source = null, ?callable $editor_source = null ) {
		$this->plugins_source = $plugins_source ?? static fn (): array => AdminFunctions::ensure( 'get_plugins' ) ? get_plugins() : [];
		$this->active_source  = $active_source ?? static fn (): array => (array) get_option( 'active_plugins', [] );
		$this->editor_source  = $editor_source ?? static function (): array {
			if ( ! function_exists( 'get_post_types' ) ) {
				return [];
			}

			$types = get_post_types( [ 'public' => true ], 'names' );
			$out   = [];

			foreach ( $types as $type ) {
				$out[ (string) $type ] = function_exists( 'use_block_editor_for_post_type' )
					? (bool) use_block_editor_for_post_type( (string) $type )
					: true;
			}

			return $out;
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'inventory.editor';
	}

	/**
	 * {@inheritDoc}
	 */
	public function group(): Group {
		return Group::INVENTORY;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Editor type', 'rtcamp-amc-copilot' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function schedule(): Schedule {
		return Schedule::WEEKLY;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Context $ctx Run context (unused here).
	 */
	public function run( Context $ctx ): Result { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by the Check contract.
		$plugins = (array) ( $this->plugins_source )();
		$active  = (array) ( $this->active_source )();
		$states  = (array) ( $this->editor_source )();

		$classic_file = null;
		foreach ( array_keys( $plugins ) as $file ) {
			if ( str_contains( (string) $file, 'classic-editor' ) ) {
				$classic_file = (string) $file;
			}
		}

		$classic_installed = null !== $classic_file;
		$classic_active    = $classic_installed && in_array( $classic_file, $active, true );

		$all_block   = true;
		$all_classic = true;

		foreach ( $states as $enabled ) {
			if ( $enabled ) {
				$all_classic = false;
			} else {
				$all_block = false;
			}
		}

		if ( $classic_active || $all_classic ) {
			$editor = 'classic';
		} elseif ( $all_block ) {
			$editor = 'block';
		} else {
			$editor = 'mixed';
		}

		return Result::pass(
			sprintf(
				/* translators: %s: Editor type. */
				__( 'Editor type: %s.', 'rtcamp-amc-copilot' ),
				$editor
			),
			[
				'classic_editor'        => $classic_installed,
				'classic_editor_active' => $classic_active,
				'post_types'            => $states,
				'editor'                => $editor,
			]
		);
	}
}
