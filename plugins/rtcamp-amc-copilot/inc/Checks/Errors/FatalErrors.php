<?php
/**
 * Check: errors.fatal.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks\Errors;

use AMC\Checks\Result;
use AMC\Checks\Severity;

/**
 * Class FatalErrors
 *
 * One finding per fatal error SIGNATURE (dedupe key = the signature), so a
 * new fatal alerts immediately and the same fatal does not alert again while
 * it keeps happening. Data shape: see AbstractEventsCheck.
 *
 * Status: any fatal in the window -> FAIL (severity HIGH); none -> PASS.
 *
 * @since 0.1.0
 */
final class FatalErrors extends AbstractEventsCheck {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'errors.fatal';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Fatal errors', 'rtcamp-amc-copilot' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function types(): array {
		return [ 'fatal' ];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $row Event row.
	 */
	protected function group_key( array $row ): string {
		return (string) $row['signature'];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function severity(): Severity {
		return Severity::HIGH;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $item Item.
	 */
	protected function title( array $item ): string {
		return sprintf(
			/* translators: 1: Error message, 2: Source, 3: File, 4: Line. */
			__( 'Fatal error: %1$s (%2$s, %3$s:%4$d)', 'rtcamp-amc-copilot' ),
			mb_substr( (string) $item['message'], 0, 120 ),
			(string) $item['source'],
			basename( (string) $item['file'] ),
			(int) $item['line']
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function clean_summary(): string {
		return __( 'No fatal errors since the last run.', 'rtcamp-amc-copilot' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string                    $summary  Summary.
	 * @param array<string, mixed>      $data     Data.
	 * @param list<\AMC\Checks\Finding> $findings Findings.
	 */
	protected function result_with_findings( string $summary, array $data, array $findings ): Result {
		return Result::fail( $summary, $data, $findings );
	}
}
