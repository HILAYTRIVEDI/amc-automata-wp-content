<?php
/**
 * Check: detect.mailer.
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
 * Class MailerDetect
 *
 * Detects the primary mail / SMTP plugin and any others installed, plus the
 * number of callbacks hooked into `phpmailer_init`.
 *
 * Result::$data shape:
 *   plugin     string|null Primary mailer slug.
 *   active     bool Whether the primary mailer is active.
 *   addons     list<array{file, name, active}>
 *   phpmailer_init_callbacks int Number of callbacks on phpmailer_init.
 *
 * Status: PASS (INFO when no mailer plugin is installed).
 *
 * @since 0.1.0
 */
final class MailerDetect implements Check {

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
	 * Returns the number of callbacks on phpmailer_init.
	 *
	 * @var callable(): int
	 */
	private $hooks_source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $plugins_source Test seam; default get_plugins().
	 * @param callable|null $active_source  Test seam; default get_option( 'active_plugins' ).
	 * @param callable|null $hooks_source   Test seam; default count of callbacks on phpmailer_init.
	 */
	public function __construct( ?callable $plugins_source = null, ?callable $active_source = null, ?callable $hooks_source = null ) {
		$this->plugins_source = $plugins_source ?? static fn (): array => AdminFunctions::ensure( 'get_plugins' ) ? get_plugins() : [];
		$this->active_source  = $active_source ?? static fn (): array => (array) get_option( 'active_plugins', [] );
		$this->hooks_source   = $hooks_source ?? static function (): int {
			global $wp_filter;

			if ( ! isset( $wp_filter['phpmailer_init'] ) ) {
				return 0;
			}

			$count = 0;
			foreach ( (array) ( $wp_filter['phpmailer_init']->callbacks ?? [] ) as $callbacks ) {
				$count += is_countable( $callbacks ) ? count( $callbacks ) : 0;
			}

			return $count;
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'detect.mailer';
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
		return __( 'Mailer detection', 'rtcamp-amc-copilot' );
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
		$detected = PluginDetect::mailers( $plugins, $active );
		$hooks    = (int) ( $this->hooks_source )();

		$data = [
			'plugin'                   => $detected['plugin'],
			'active'                   => $detected['active'],
			'addons'                   => $detected['addons'],
			'phpmailer_init_callbacks' => $hooks,
		];

		if ( null === $detected['plugin'] ) {
			return Result::info( __( 'No mailer plugin detected; WordPress uses the default mailer.', 'rtcamp-amc-copilot' ), $data );
		}

		return Result::pass(
			sprintf( /* translators: %s: Mailer plugin. */ __( 'Mailer plugin detected: %s.', 'rtcamp-amc-copilot' ), $detected['plugin'] ),
			$data
		);
	}
}
