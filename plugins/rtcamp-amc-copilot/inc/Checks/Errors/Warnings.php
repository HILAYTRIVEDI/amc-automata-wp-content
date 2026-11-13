<?php
/**
 * Check: errors.warnings.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks\Errors;

use AMC\Checks\Result;
use AMC\Checks\Severity;

/**
 * Class Warnings
 *
 * Warnings and notices, one finding per SOURCE (core, `plugin:<slug>`,
 * `theme:<slug>`, ...). Dedupe key `source:<source>`, so a new source is a new
 * finding (digest) and a noisy plugin is one line, not hundreds. Data shape:
 * see AbstractEventsCheck (`items[].signatures` says how many distinct
 * warnings the source produced).
 *
 * Status: any warning or notice in the window -> WARN (severity LOW); none -> PASS.
 *
 * @since 0.1.0
 */
final class Warnings extends AbstractEventsCheck {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'errors.warnings';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'PHP warnings and notices', 'rtcamp-amc-copilot' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function types(): array {
		return [ 'warning', 'notice' ];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $row Event row.
	 */
	protected function group_key( array $row ): string {
		return 'source:' . ( '' === (string) $row['source'] ? 'unknown' : (string) $row['source'] );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function severity(): Severity {
		return Severity::LOW;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $item Item.
	 */
	protected function title( array $item ): string {
		return sprintf(
			/* translators: 1: Source (core, plugin:slug), 2: Number of events, 3: Number of distinct warnings. */
			__( 'PHP warnings from %1$s: %2$d events, %3$d distinct', 'rtcamp-amc-copilot' ),
			'' === (string) $item['source'] ? 'unknown' : (string) $item['source'],
			(int) $item['count'],
			(int) $item['signatures']
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function clean_summary(): string {
		return __( 'No PHP warnings or notices since the last run.', 'rtcamp-amc-copilot' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string                    $summary  Summary.
	 * @param array<string, mixed>      $data     Data.
	 * @param list<\AMC\Checks\Finding> $findings Findings.
	 */
	protected function result_with_findings( string $summary, array $data, array $findings ): Result {
		return Result::warn( $summary, $data, $findings );
	}
}
