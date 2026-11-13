<?php
/**
 * Check: errors.deprecations.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks\Errors;

use AMC\Checks\Result;
use AMC\Checks\Severity;

/**
 * Class Deprecations
 *
 * `deprecated_*_run` and `doing_it_wrong_run` events, one finding per CALLER:
 * the source plus the file that made the call. Dedupe key
 * `caller:<md5(source|file)>`, so a new caller is a new finding (digest).
 * Data shape: see AbstractEventsCheck.
 *
 * Status: any in the window -> INFO (severity LOW; deprecations rarely break
 * a site but pile up before a PHP or WordPress upgrade); none -> PASS.
 *
 * @since 0.1.0
 */
final class Deprecations extends AbstractEventsCheck {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'errors.deprecations';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Deprecations', 'rtcamp-amc-copilot' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function types(): array {
		return [ 'deprecation', 'doing_it_wrong' ];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $row Event row.
	 */
	protected function group_key( array $row ): string {
		return 'caller:' . md5( (string) $row['source'] . '|' . (string) $row['file'] );
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
			/* translators: 1: Caller file, 2: Source (core, plugin:slug), 3: Number of events. */
			__( 'Deprecated calls from %1$s (%2$s): %3$d events', 'rtcamp-amc-copilot' ),
			basename( (string) $item['file'] ),
			'' === (string) $item['source'] ? 'unknown' : (string) $item['source'],
			(int) $item['count']
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function clean_summary(): string {
		return __( 'No deprecations since the last run.', 'rtcamp-amc-copilot' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string                    $summary  Summary.
	 * @param array<string, mixed>      $data     Data.
	 * @param list<\AMC\Checks\Finding> $findings Findings.
	 */
	protected function result_with_findings( string $summary, array $data, array $findings ): Result {
		return Result::info( $summary, $data, $findings );
	}
}
