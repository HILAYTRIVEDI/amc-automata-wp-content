<?php
/**
 * Check: users.roles.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks\Users;

use AMC\Checks\Check;
use AMC\Checks\Context;
use AMC\Checks\Finding;
use AMC\Checks\Group;
use AMC\Checks\Result;
use AMC\Checks\Schedule;
use AMC\Checks\Severity;
use AMC\Storage\SnapshotsRepository;

/**
 * Class Roles
 *
 * Reports user counts per role and diffs role/capability sets against the
 * previous snapshot (kind `roles`).
 *
 * Result::$data shape:
 *   counts   array<string, int> Role => count.
 *   total    int Total users.
 *   roles    list<array{name, capabilities: list<string>}>
 *   changes  list<array{role, added: list<string>, removed: list<string>}>
 *
 * Status: PASS, or WARN + Finding MEDIUM when capabilities changed.
 *
 * @since 0.1.0
 */
final class Roles implements Check {

	/**
	 * Returns count_users() result.
	 *
	 * @var callable(): array<string, mixed>
	 */
	private $count_source;

	/**
	 * Returns the role definitions.
	 *
	 * @var callable(): array<string, array<string, mixed>>
	 */
	private $roles_source;

	/**
	 * Snapshots repository.
	 *
	 * @var SnapshotsRepository
	 */
	private $snapshots;

	/**
	 * Constructor.
	 *
	 * @param callable|null            $count_source Test seam; default count_users().
	 * @param callable|null            $roles_source Test seam; default wp_roles()->roles.
	 * @param SnapshotsRepository|null $snapshots    Test seam; default new SnapshotsRepository().
	 */
	public function __construct( ?callable $count_source = null, ?callable $roles_source = null, ?SnapshotsRepository $snapshots = null ) {
		$this->count_source = $count_source ?? static fn (): array => (array) count_users();
		$this->roles_source = $roles_source ?? static function (): array {
			if ( ! function_exists( 'wp_roles' ) ) {
				return [];
			}

			return (array) wp_roles()->roles;
		};
		$this->snapshots    = $snapshots ?? new SnapshotsRepository();
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'users.roles';
	}

	/**
	 * {@inheritDoc}
	 */
	public function group(): Group {
		return Group::USERS;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'User roles and capabilities', 'rtcamp-amc-copilot' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function schedule(): Schedule {
		return Schedule::DAILY;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Context $ctx Run context.
	 */
	public function run( Context $ctx ): Result { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by the Check contract.
		$counts = (array) ( $this->count_source )();
		$roles  = (array) ( $this->roles_source )();

		$role_rows = [];
		foreach ( $roles as $name => $def ) {
			$def  = is_array( $def ) ? $def : [];
			$caps = isset( $def['capabilities'] ) && is_array( $def['capabilities'] ) ? array_keys( $def['capabilities'] ) : [];
			sort( $caps );

			$role_rows[] = [
				'name'         => (string) $name,
				'capabilities' => $caps,
			];
		}

		$role_counts = [];
		if ( isset( $counts['avail_roles'] ) && is_array( $counts['avail_roles'] ) ) {
			$role_counts = $counts['avail_roles'];
		}

		$total = isset( $counts['total_users'] ) ? (int) $counts['total_users'] : array_sum( $role_counts );

		// Diff against the previous snapshot.
		$previous = $this->snapshots->latest( 'roles' );
		$changes  = [];

		if ( is_array( $previous ) && isset( $previous['data']['roles'] ) && is_array( $previous['data']['roles'] ) ) {
			$previous_map = [];
			foreach ( $previous['data']['roles'] as $row ) {
				if ( is_array( $row ) && isset( $row['name'] ) ) {
					$previous_map[ (string) $row['name'] ] = $row['capabilities'] ?? [];
				}
			}

			foreach ( $role_rows as $row ) {
				$name    = $row['name'];
				$prev    = $previous_map[ $name ] ?? [];
				$added   = array_values( array_diff( $row['capabilities'], $prev ) );
				$removed = array_values( array_diff( $prev, $row['capabilities'] ) );

				if ( [] !== $added || [] !== $removed ) {
					$changes[] = [
						'role'    => $name,
						'added'   => $added,
						'removed' => $removed,
					];
				}
			}
		}

		$this->snapshots->insert( 'roles', [ 'roles' => $role_rows ] );

		$findings = [];
		foreach ( $changes as $change ) {
			$findings[] = new Finding(
				'role:' . $change['role'] . ':caps',
				Severity::MEDIUM,
				sprintf( /* translators: %s: Role name. */ __( 'Capabilities changed for role: %s.', 'rtcamp-amc-copilot' ), $change['role'] ),
				$change
			);
		}

		$data = [
			'counts'  => $role_counts,
			'total'   => $total,
			'roles'   => $role_rows,
			'changes' => $changes,
		];

		if ( [] !== $findings ) {
			return Result::warn( __( 'Role capabilities changed since the last run.', 'rtcamp-amc-copilot' ), $data, $findings );
		}

		return Result::pass( __( 'User roles and capabilities are unchanged.', 'rtcamp-amc-copilot' ), $data );
	}
}
