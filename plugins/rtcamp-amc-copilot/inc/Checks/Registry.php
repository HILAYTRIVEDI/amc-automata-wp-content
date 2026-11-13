<?php
/**
 * Registry of every check.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks;

/**
 * Class Registry
 *
 * Collects checks from the `amc_register_checks` filter:
 *
 *   add_filter( 'amc_register_checks', function ( array $checks ): array {
 *       $checks[] = new Updates\CoreUpdates();
 *       return $checks;
 *   } );
 *
 * Lanes never call that add_filter() by hand: each lane adds its checks to
 * its OWN provider class in inc/Checks/Providers/ (see docs/CONTRACTS.md).
 *
 * The registry is cheap and has no global state: `new Registry()` wherever
 * you need it (Runner, Scheduler, REST, CLI). It applies the filter once per
 * instance. It is never built on front-end requests.
 *
 * @since 0.1.0
 */
final class Registry {

	/**
	 * Filter name.
	 */
	public const FILTER = 'amc_register_checks';

	/**
	 * Memoised checks keyed by id.
	 *
	 * @var array<string, Check>|null
	 */
	private ?array $checks = null;

	/**
	 * Every registered check, keyed by id, sorted by id.
	 *
	 * Invalid entries (not a Check, bad id, duplicate id) are skipped with a
	 * _doing_it_wrong() notice so one broken check never hides the others.
	 *
	 * @return array<string, Check>
	 */
	public function all(): array {
		if ( null !== $this->checks ) {
			return $this->checks;
		}

		/**
		 * Register checks.
		 *
		 * @param list<Check> $checks Checks registered so far.
		 */
		$candidates = apply_filters( 'amc_register_checks', [] );
		$checks     = [];

		foreach ( is_array( $candidates ) ? $candidates : [] as $check ) {
			if ( ! $check instanceof Check ) {
				_doing_it_wrong( __METHOD__, esc_html__( 'amc_register_checks entries must implement AMC\Checks\Check.', 'rtcamp-amc-copilot' ), '0.1.0' );
				continue;
			}

			$id = $check->id();
			if ( 1 !== preg_match( '/^[a-z0-9_]+\.[a-z0-9_]+$/', $id ) || isset( $checks[ $id ] ) ) {
				_doing_it_wrong( __METHOD__, esc_html( sprintf( 'Invalid or duplicate check id "%s".', $id ) ), '0.1.0' );
				continue;
			}

			$checks[ $id ] = $check;
		}

		ksort( $checks );

		$this->checks = $checks;

		return $checks;
	}

	/**
	 * One check by id.
	 *
	 * @param string $id Check id.
	 */
	public function get( string $id ): ?Check {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * Whether a check id is registered.
	 *
	 * @param string $id Check id.
	 */
	public function has( string $id ): bool {
		return null !== $this->get( $id );
	}

	/**
	 * Checks in one group.
	 *
	 * @param Group $group Group.
	 *
	 * @return array<string, Check>
	 */
	public function in_group( Group $group ): array {
		return array_filter( $this->all(), static fn ( Check $check ): bool => $check->group() === $group );
	}

	/**
	 * Effective schedule of a check: the Settings override, else Check::schedule().
	 *
	 * @param Check $check Check.
	 */
	public static function effective_schedule( Check $check ): Schedule {
		$override = \AMC\Core\Settings::check_interval( $check->id() );

		return '' !== $override ? ( Schedule::tryFrom( $override ) ?? $check->schedule() ) : $check->schedule();
	}
}
