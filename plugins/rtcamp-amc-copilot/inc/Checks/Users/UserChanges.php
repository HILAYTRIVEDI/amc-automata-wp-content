<?php
/**
 * Check: users.changes.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks\Users;

use AMC\Checks\Check;
use AMC\Checks\Context;
use AMC\Checks\Group;
use AMC\Checks\Result;
use AMC\Checks\Schedule;
use AMC\Storage\ChangesRepository;

/**
 * Class UserChanges
 *
 * Summarises `amc_changes` rows of type `user` (the hooks themselves belong
 * to A1). Scheduled as `event` (on demand only).
 *
 * Result::$data shape:
 *   total   int Number of user changes in the window.
 *   items   list<array{id, action, item, item_name, user_id, created_at}>
 *   counts  array<string, int> action => count.
 *
 * Status: PASS (informational).
 *
 * @since 0.1.0
 */
final class UserChanges implements Check {

	/**
	 * Returns the recent user changes.
	 *
	 * @var callable(): array{items: list<array<string, mixed>>, total: int}
	 */
	private $changes_source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $changes_source Test seam; default ChangesRepository::query().
	 */
	public function __construct( ?callable $changes_source = null ) {
		$this->changes_source = $changes_source ?? static function (): array {
			return ( new ChangesRepository() )->query(
				[
					'type'     => 'user',
					'per_page' => 50,
				]
			);
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'users.changes';
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
		return __( 'User changes', 'rtcamp-amc-copilot' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function schedule(): Schedule {
		return Schedule::EVENT;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Context $ctx Run context (unused here).
	 */
	public function run( Context $ctx ): Result { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by the Check contract.
		$result = (array) ( $this->changes_source )();
		$items  = is_array( $result['items'] ?? null ) ? $result['items'] : [];
		$total  = (int) ( $result['total'] ?? count( $items ) );

		$counts = [];
		foreach ( $items as $row ) {
			$action            = (string) ( $row['action'] ?? '' );
			$counts[ $action ] = ( $counts[ $action ] ?? 0 ) + 1;
		}

		$normalised = [];
		foreach ( $items as $row ) {
			$normalised[] = [
				'id'         => (int) ( $row['id'] ?? 0 ),
				'action'     => (string) ( $row['action'] ?? '' ),
				'item'       => (string) ( $row['item'] ?? '' ),
				'item_name'  => (string) ( $row['item_name'] ?? '' ),
				'user_id'    => (int) ( $row['user_id'] ?? 0 ),
				'created_at' => (string) ( $row['created_at'] ?? '' ),
			];
		}

		return Result::pass(
			sprintf(
				/* translators: %s: Number of user changes. */
				_n( '%s user change recorded.', '%s user changes recorded.', $total, 'rtcamp-amc-copilot' ),
				number_format_i18n( $total )
			),
			[
				'total'  => $total,
				'items'  => $normalised,
				'counts' => $counts,
			]
		);
	}
}
