<?php
/**
 * Check: inventory.themes.
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
use AMC\Storage\SnapshotsRepository;

/**
 * Class ThemeInventory
 *
 * Inventory of installed themes: active theme and parent, plus a weekly
 * snapshot (kind `themes`).
 *
 * Result::$data shape:
 *   themes list<array{stylesheet, name, version, active, parent}>
 *   total int, active string|null
 *
 * Status: PASS (no alert-worthy findings).
 *
 * @since 0.1.0
 */
final class ThemeInventory implements Check {

	/**
	 * Returns the installed themes.
	 *
	 * @var callable(): array<string, mixed>
	 */
	private $themes_source;

	/**
	 * Returns the active theme stylesheet and template.
	 *
	 * @var callable(): array{stylesheet: string, template: string}
	 */
	private $active_source;

	/**
	 * Snapshots repository used to store the weekly snapshot.
	 *
	 * @var SnapshotsRepository
	 */
	private $snapshots;

	/**
	 * Constructor.
	 *
	 * @param callable|null            $themes_source Test seam; default wp_get_themes().
	 * @param callable|null            $active_source Test seam; default get_option( 'stylesheet' ) and 'template'.
	 * @param SnapshotsRepository|null $snapshots     Test seam; default new SnapshotsRepository().
	 */
	public function __construct( ?callable $themes_source = null, ?callable $active_source = null, ?SnapshotsRepository $snapshots = null ) {
		$this->themes_source = $themes_source ?? static fn (): array => wp_get_themes();
		$this->active_source = $active_source ?? static fn (): array => [
			'stylesheet' => (string) get_option( 'stylesheet' ),
			'template'   => (string) get_option( 'template' ),
		];
		$this->snapshots     = $snapshots ?? new SnapshotsRepository();
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'inventory.themes';
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
		return __( 'Theme inventory', 'rtcamp-amc-copilot' );
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
		$themes     = (array) ( $this->themes_source )();
		$active     = (array) ( $this->active_source )();
		$stylesheet = (string) ( $active['stylesheet'] ?? '' );

		$rows = [];
		foreach ( $themes as $slug => $theme ) {
			$parent = method_exists( $theme, 'parent' ) ? $theme->parent() : false;

			$rows[] = [
				'stylesheet' => (string) $slug,
				'name'       => (string) $theme->get( 'Name' ),
				'version'    => (string) $theme->get( 'Version' ),
				'active'     => (string) $slug === $stylesheet,
				'parent'     => $parent instanceof \WP_Theme ? $parent->get_stylesheet() : null,
			];
		}

		$this->snapshots->insert( 'themes', [ 'themes' => $rows ] );

		return Result::pass(
			sprintf(
				/* translators: %s: Number of installed themes. */
				_n( '%s theme installed.', '%s themes installed.', count( $rows ), 'rtcamp-amc-copilot' ),
				number_format_i18n( count( $rows ) )
			),
			[
				'themes' => $rows,
				'total'  => count( $rows ),
				'active' => $stylesheet ?: null,
			]
		);
	}
}
