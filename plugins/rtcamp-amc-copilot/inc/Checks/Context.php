<?php
/**
 * Context passed to Check::run().
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks;

/**
 * Class Context
 *
 * FROZEN CONTRACT (Wave 0). Immutable. Built by the Runner, never by checks.
 *
 * - $blog_id:       the site being checked. The runner has ALREADY switched
 *                   to it, so plain WordPress functions read the right site.
 *                   Use $ctx->blog_id when you need the ID; never call
 *                   get_current_blog_id().
 * - $trigger:       'cron', 'manual' or 'cli'.
 * - $run_id:        the amc_runs row being produced (0 in unit tests).
 * - $now:           the run's start time (UTC). Use it instead of time() so
 *                   tests can pin the clock.
 * - $previous_data: Result::$data of this check's previous finished run, or
 *                   null on the first run. Use it for "changed since last
 *                   run" diffs and batch cursors.
 *
 * @since 0.1.0
 */
final class Context {

	/**
	 * Constructor.
	 *
	 * @param int                       $blog_id       Site being checked.
	 * @param string                    $trigger       'cron' | 'manual' | 'cli'.
	 * @param int                       $run_id        amc_runs.id (0 when not stored).
	 * @param \DateTimeImmutable        $now           Run start, UTC.
	 * @param array<string, mixed>|null $previous_data Previous run's data, or null.
	 */
	public function __construct(
		public readonly int $blog_id,
		public readonly string $trigger = 'cron',
		public readonly int $run_id = 0,
		public readonly \DateTimeImmutable $now = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ),
		public readonly ?array $previous_data = null
	) {}

	/**
	 * Context for unit tests: blog 1, manual trigger, optional pinned clock/previous data.
	 *
	 * @param array<string, mixed>|null $previous_data Previous data.
	 * @param string                    $now           Any strtotime() string, UTC.
	 *
	 * @return static
	 */
	public static function for_tests( ?array $previous_data = null, string $now = 'now' ): self {
		return new self( 1, 'manual', 0, new \DateTimeImmutable( $now, new \DateTimeZone( 'UTC' ) ), $previous_data );
	}
}
