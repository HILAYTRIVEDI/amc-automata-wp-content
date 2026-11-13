<?php
/**
 * Check: security.admins (administrator accounts and changes).
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks\Security;

use AMC\Checks\Check;
use AMC\Checks\Context;
use AMC\Checks\Finding;
use AMC\Checks\Group;
use AMC\Checks\Result;
use AMC\Checks\Schedule;
use AMC\Checks\Severity;
use AMC\Storage\SnapshotsRepository;

/**
 * Class AdminSnapshot
 *
 * Lists the users with the administrator role and diffs them against the
 * latest `admins` snapshot in `amc_snapshots` (this check owns that kind).
 * A new snapshot is stored only when the list changed, so history stays
 * small. The first run only records a baseline and raises nothing.
 *
 * Changes (per user ID):
 *   added         In the list now, not in the snapshot (promoted or created). HIGH, immediate alert.
 *   removed       In the snapshot, and the account no longer exists. LOW, digest.
 *   role_changed  In the snapshot, the account still exists, but is no longer an administrator. MEDIUM, digest.
 *
 * Result::$data shape:
 *   admins       list<array{id: int, login: string, email: string, display_name: string}>
 *   count        int
 *   baseline     bool  True on the first run (nothing to compare with).
 *   snapshot_at  string|null  When the snapshot compared with was taken (UTC 'Y-m-d H:i:s').
 *   changes      list<array{change: 'added'|'removed'|'role_changed', user_id: int, login: string, email: string}>
 *
 * Status rules: baseline -> INFO; an added admin -> FAIL; other changes -> WARN; none -> PASS.
 *
 * Findings: one per change, dedupe key `admin:<change>:<user_id>`, Finding data
 * `{ change, user_id, login, email }`. `change` is what Notify routes on. A
 * finding lasts for one run: the next run compares with the updated snapshot
 * and resolves it.
 *
 * @since 0.1.0
 */
final class AdminSnapshot implements Check {

	/**
	 * Current administrators: callable(): list<array{id: int, login: string, email: string, display_name: string}>.
	 *
	 * @var callable
	 */
	private $admins_source;

	/**
	 * Latest snapshot: callable( int $blog_id ): array{data: array, taken_at: string}|null.
	 *
	 * @var callable
	 */
	private $snapshot_reader;

	/**
	 * Store a snapshot: callable( array $admins, int $blog_id ): void.
	 *
	 * @var callable
	 */
	private $snapshot_writer;

	/**
	 * Whether a user account still exists: callable( int $user_id ): bool.
	 *
	 * @var callable
	 */
	private $user_exists;

	/**
	 * Constructor.
	 *
	 * @param callable|null $admins_source   Test seam; default get_users( role=administrator ).
	 * @param callable|null $snapshot_reader Test seam; default SnapshotsRepository::latest( 'admins' ).
	 * @param callable|null $snapshot_writer Test seam; default SnapshotsRepository::insert( 'admins' ).
	 * @param callable|null $user_exists     Test seam; default get_userdata().
	 */
	public function __construct( ?callable $admins_source = null, ?callable $snapshot_reader = null, ?callable $snapshot_writer = null, ?callable $user_exists = null ) {
		$this->admins_source   = $admins_source ?? [ self::class, 'current_admins' ];
		$this->snapshot_reader = $snapshot_reader ?? static function ( int $blog_id ): ?array {
			$row = ( new SnapshotsRepository() )->latest( 'admins', $blog_id );

			return null === $row ? null : [
				'data'     => (array) $row['data'],
				'taken_at' => (string) $row['taken_at'],
			];
		};
		$this->snapshot_writer = $snapshot_writer ?? static function ( array $admins, int $blog_id ): void {
			( new SnapshotsRepository() )->insert( 'admins', [ 'admins' => $admins ], $blog_id );
		};
		$this->user_exists     = $user_exists ?? static fn ( int $user_id ): bool => false !== get_userdata( $user_id );
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'security.admins';
	}

	/**
	 * {@inheritDoc}
	 */
	public function group(): Group {
		return Group::SECURITY;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Administrator accounts', 'rtcamp-amc-copilot' );
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
	public function run( Context $ctx ): Result {
		$admins   = $this->normalise( ( $this->admins_source )() );
		$snapshot = ( $this->snapshot_reader )( $ctx->blog_id );
		$data     = [
			'admins'      => $admins,
			'count'       => count( $admins ),
			'baseline'    => null === $snapshot,
			'snapshot_at' => null === $snapshot ? null : $snapshot['taken_at'],
			'changes'     => [],
		];

		if ( null === $snapshot ) {
			( $this->snapshot_writer )( $admins, $ctx->blog_id );

			return Result::info(
				sprintf(
					/* translators: %d: Number of administrators. */
					_n( 'Baseline recorded: %d administrator.', 'Baseline recorded: %d administrators.', count( $admins ), 'rtcamp-amc-copilot' ),
					count( $admins )
				),
				$data
			);
		}

		$before = [];
		foreach ( $this->normalise( (array) ( $snapshot['data']['admins'] ?? [] ) ) as $row ) {
			$before[ $row['id'] ] = $row;
		}

		$now = [];
		foreach ( $admins as $row ) {
			$now[ $row['id'] ] = $row;
		}

		foreach ( $now as $id => $row ) {
			if ( ! isset( $before[ $id ] ) ) {
				$data['changes'][] = $this->change( 'added', $row );
			}
		}

		foreach ( $before as $id => $row ) {
			if ( ! isset( $now[ $id ] ) ) {
				$data['changes'][] = $this->change( ( $this->user_exists )( $id ) ? 'role_changed' : 'removed', $row );
			}
		}

		if ( [] === $data['changes'] ) {
			return Result::pass(
				sprintf(
					/* translators: %d: Number of administrators. */
					_n( '%d administrator, no changes.', '%d administrators, no changes.', count( $admins ), 'rtcamp-amc-copilot' ),
					count( $admins )
				),
				$data
			);
		}

		( $this->snapshot_writer )( $admins, $ctx->blog_id );

		$findings = [];
		$added    = false;

		foreach ( $data['changes'] as $change ) {
			$added      = $added || 'added' === $change['change'];
			$findings[] = new Finding(
				'admin:' . $change['change'] . ':' . $change['user_id'],
				match ( $change['change'] ) {
					'added'        => Severity::HIGH,
					'role_changed' => Severity::MEDIUM,
					default        => Severity::LOW,
				},
				match ( $change['change'] ) {
					'added'        => sprintf(
						/* translators: 1: Login, 2: Email. */
						__( 'Administrator added: %1$s (%2$s)', 'rtcamp-amc-copilot' ),
						$change['login'],
						$change['email']
					),
					'role_changed' => sprintf(
						/* translators: 1: Login, 2: Email. */
						__( 'Administrator role removed: %1$s (%2$s)', 'rtcamp-amc-copilot' ),
						$change['login'],
						$change['email']
					),
					default        => sprintf(
						/* translators: 1: Login, 2: Email. */
						__( 'Administrator account deleted: %1$s (%2$s)', 'rtcamp-amc-copilot' ),
						$change['login'],
						$change['email']
					),
				},
				$change
			);
		}

		$summary = sprintf(
			/* translators: %d: Number of changes. */
			_n( '%d administrator change since the last snapshot.', '%d administrator changes since the last snapshot.', count( $findings ), 'rtcamp-amc-copilot' ),
			count( $findings )
		);

		return $added ? Result::fail( $summary, $data, $findings ) : Result::warn( $summary, $data, $findings );
	}

	/**
	 * A change entry, which is also the finding data.
	 *
	 * @param string                                                             $change added, removed or role_changed.
	 * @param array{id: int, login: string, email: string, display_name: string} $row    The administrator.
	 *
	 * @return array{change: string, user_id: int, login: string, email: string}
	 */
	private function change( string $change, array $row ): array {
		return [
			'change'  => $change,
			'user_id' => $row['id'],
			'login'   => $row['login'],
			'email'   => $row['email'],
		];
	}

	/**
	 * Clean rows and sort them by ID.
	 *
	 * @param mixed $rows Rows.
	 *
	 * @return list<array{id: int, login: string, email: string, display_name: string}>
	 */
	private function normalise( mixed $rows ): array {
		$out = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'] ) ) {
				continue;
			}

			$out[ (int) $row['id'] ] = [
				'id'           => (int) $row['id'],
				'login'        => (string) ( $row['login'] ?? '' ),
				'email'        => (string) ( $row['email'] ?? '' ),
				'display_name' => (string) ( $row['display_name'] ?? '' ),
			];
		}

		ksort( $out );

		return array_values( $out );
	}

	/**
	 * The administrators of the current site.
	 *
	 * @return list<array{id: int, login: string, email: string, display_name: string}>
	 */
	public static function current_admins(): array {
		$users = get_users(
			[
				'role'    => 'administrator',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'number'  => 500,
			]
		);

		return array_map(
			static fn ( \WP_User $user ): array => [
				'id'           => (int) $user->ID,
				'login'        => $user->user_login,
				'email'        => $user->user_email,
				'display_name' => $user->display_name,
			],
			$users
		);
	}
}
