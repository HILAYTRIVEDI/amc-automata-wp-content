<?php
/**
 * Check: inventory.mu_dropins.
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

/**
 * Class MuDropins
 *
 * Inventory of must-use plugins and drop-ins.
 *
 * Result::$data shape:
 *   mu_plugins list<array{name, version, description, file}>
 *   dropins    list<array{file, description, active}>
 *   total      int
 *
 * Status: PASS.
 *
 * @since 0.1.0
 */
final class MuDropins implements Check {

	/**
	 * Returns the must-use plugins.
	 *
	 * @var callable(): array<string, array<string, mixed>>
	 */
	private $mu_source;

	/**
	 * Returns the registered drop-ins.
	 *
	 * @var callable(): array<string, array{0: string, 1: bool|string}>
	 */
	private $dropins_source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $mu_source      Test seam; default get_mu_plugins().
	 * @param callable|null $dropins_source Test seam; default _get_dropins().
	 */
	public function __construct( ?callable $mu_source = null, ?callable $dropins_source = null ) {
		$this->mu_source      = $mu_source ?? static fn (): array => function_exists( 'get_mu_plugins' ) ? get_mu_plugins() : [];
		$this->dropins_source = $dropins_source ?? static fn (): array => function_exists( '_get_dropins' ) ? _get_dropins() : [];
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'inventory.mu_dropins';
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
		return __( 'Must-use plugins and drop-ins', 'rtcamp-amc-copilot' );
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
		$mu      = (array) ( $this->mu_source )();
		$dropins = (array) ( $this->dropins_source )();

		$mu_rows = [];
		foreach ( $mu as $file => $meta ) {
			$meta      = is_array( $meta ) ? $meta : [];
			$mu_rows[] = [
				'file'        => (string) $file,
				'name'        => (string) ( $meta['Name'] ?? $file ),
				'version'     => (string) ( $meta['Version'] ?? '' ),
				'description' => (string) ( $meta['Description'] ?? '' ),
			];
		}

		$dropin_rows = [];
		foreach ( $dropins as $file => $meta ) {
			$meta          = is_array( $meta ) ? $meta : [];
			$dropin_rows[] = [
				'file'        => (string) $file,
				'description' => (string) ( $meta[0] ?? '' ),
				'active'      => ! empty( $meta[1] ),
			];
		}

		return Result::pass(
			sprintf(
				/* translators: 1: MU plugins, 2: Drop-ins. */
				__( '%1$d must-use plugins and %2$d drop-ins found.', 'rtcamp-amc-copilot' ),
				count( $mu_rows ),
				count( $dropin_rows )
			),
			[
				'mu_plugins' => $mu_rows,
				'dropins'    => $dropin_rows,
				'total'      => count( $mu_rows ) + count( $dropin_rows ),
			]
		);
	}
}
