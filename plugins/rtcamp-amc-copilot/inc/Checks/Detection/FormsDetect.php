<?php
/**
 * Check: detect.forms.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks\Detection;

use AMC\Checks\Check;
use AMC\Checks\Context;
use AMC\Checks\Group;
use AMC\Checks\Result;
use AMC\Checks\Schedule;
use AMC\Support\AdminFunctions;
use AMC\Support\PluginDetect;

/**
 * Class FormsDetect
 *
 * Detects the active form plugin family, its add-ons and (where the plugin
 * offers an API) the number of forms.
 *
 * Result::$data shape:
 *   plugin     string|null Form plugin family slug.
 *   active     bool Whether the main plugin is active.
 *   addons     list<array{file, name, active}>
 *   form_count int|null Number of forms, when the plugin offers an API.
 *
 * Status: PASS.
 *
 * @since 0.1.0
 */
final class FormsDetect implements Check {

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
	 * Returns the number of forms for the detected plugin, or null.
	 *
	 * @var callable(string|null): int|null
	 */
	private $count_source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $plugins_source Test seam; default get_plugins().
	 * @param callable|null $active_source  Test seam; default get_option( 'active_plugins' ).
	 * @param callable|null $count_source   Test seam; default a best-effort counter.
	 */
	public function __construct( ?callable $plugins_source = null, ?callable $active_source = null, ?callable $count_source = null ) {
		$this->plugins_source = $plugins_source ?? static fn (): array => AdminFunctions::ensure( 'get_plugins' ) ? get_plugins() : [];
		$this->active_source  = $active_source ?? static fn (): array => (array) get_option( 'active_plugins', [] );
		$this->count_source   = $count_source ?? static fn ( ?string $plugin ): ?int => null; // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'detect.forms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function group(): Group {
		return Group::DETECTION;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Form plugin detection', 'rtcamp-amc-copilot' );
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
		$plugins  = (array) ( $this->plugins_source )();
		$active   = (array) ( $this->active_source )();
		$detected = PluginDetect::forms( $plugins, $active );

		$count = ( $this->count_source )( $detected['plugin'] );

		return Result::pass(
			$detected['plugin'] ? sprintf( /* translators: %s: Form plugin. */ __( 'Form plugin detected: %s.', 'rtcamp-amc-copilot' ), $detected['plugin'] ) : __( 'No form plugin detected.', 'rtcamp-amc-copilot' ),
			[
				'plugin'     => $detected['plugin'],
				'active'     => $detected['active'],
				'addons'     => $detected['addons'],
				'form_count' => $count,
			]
		);
	}
}
