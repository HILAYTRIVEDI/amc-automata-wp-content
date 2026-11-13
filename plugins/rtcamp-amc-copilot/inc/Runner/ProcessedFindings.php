<?php
/**
 * Payload of the `amc_findings_processed` action.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Runner;

/**
 * Class ProcessedFindings
 *
 * FROZEN CONTRACT (Wave 0). Passed as the single argument of
 *
 *   do_action( 'amc_findings_processed', ProcessedFindings $processed );
 *
 * after every successful run (not after a run that errored: an error must
 * never resolve findings). Notify (A2) consumes it:
 *
 *   add_action( 'amc_findings_processed', [ $this, 'on_findings' ] );
 *   public function on_findings( ProcessedFindings $p ): void {
 *       foreach ( $p->new as $finding ) { ... } // rows from FindingsRepository
 *   }
 *
 * Each list holds amc_findings rows (FindingsRepository row shape) AFTER
 * the runner wrote them.
 *
 * @since 0.1.0
 */
final class ProcessedFindings {

	/**
	 * Constructor.
	 *
	 * @param int                        $run_id   Run that produced them.
	 * @param string                     $check_id Check id.
	 * @param int                        $blog_id  Site.
	 * @param string                     $trigger  'cron' | 'manual' | 'cli'.
	 * @param list<array<string, mixed>> $new      Findings seen for the first time (or back after resolving).
	 * @param list<array<string, mixed>> $ongoing  Unresolved findings seen again.
	 * @param list<array<string, mixed>> $resolved Findings that disappeared and are now resolved.
	 */
	public function __construct(
		public readonly int $run_id,
		public readonly string $check_id,
		public readonly int $blog_id,
		public readonly string $trigger,
		public readonly array $new = [], // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.newFound -- Public contract name.
		public readonly array $ongoing = [],
		public readonly array $resolved = []
	) {}
}
