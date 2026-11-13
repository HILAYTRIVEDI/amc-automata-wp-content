<?php
/**
 * Registers the AMC Copilot abilities with the WordPress Abilities API.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Abilities;

use AMC\Checks\Registry;
use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractAbility;
use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractAbilityRegistrar;

/**
 * Class AbilitiesRegistrar
 *
 * One read-only ability per registered check (`amc-copilot/<check-id>`, with
 * dots turned into dashes, e.g. `amc-copilot/updates-core`) plus the report
 * read actions. Everything here needs the `amc_manage` capability and only
 * READS: it returns stored results, it never runs a check or changes a report.
 * Write actions (report generate / regenerate, "Run now") are not abilities.
 *
 * WordPress 6.9+ only: on older cores the Abilities API hooks never fire and
 * this class does nothing.
 *
 * @since 0.1.0
 */
final class AbilitiesRegistrar extends AbstractAbilityRegistrar {

	/**
	 * Ability category slug.
	 */
	public const CATEGORY = 'amc-copilot';

	/**
	 * {@inheritDoc}
	 */
	protected function category_slug(): string {
		return self::CATEGORY;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function category_label(): string {
		return __( 'AMC Copilot', 'rtcamp-amc-copilot' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function category_description(): string {
		return __( 'Read-only access to the AMC Copilot site-maintenance checks, their latest results and open findings, and to maintenance reports.', 'rtcamp-amc-copilot' );
	}

	/**
	 * Public so tests and tooling can list what would be registered.
	 *
	 * @return list<AbstractAbility>
	 */
	public function list_abilities(): array {
		return $this->abilities();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return list<AbstractAbility>
	 */
	protected function abilities(): array {
		$abilities = [];

		foreach ( ( new Registry() )->all() as $check ) {
			$abilities[] = new CheckAbility( $check );
		}

		$abilities[] = new ReportsAbility();
		$abilities[] = new ReportSnapshotAbility();

		return $abilities;
	}
}
