<?php
/**
 * The check contract.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Checks;

/**
 * Interface Check
 *
 * FROZEN CONTRACT (Wave 0). One class per check. The worked example is
 * AMC\Checks\Updates\CoreUpdates; copy it.
 *
 * Rules:
 * 1. run() READS the site and returns a Result. It never writes to the
 *    site: no update_option(), no transients, no remote POSTs, no files.
 *    (Storing runs and findings is the Runner's job, not the check's.)
 * 2. run() never calls get_current_blog_id() or switch_to_blog(). The
 *    runner has already switched to $ctx->blog_id.
 * 3. run() finishes in under 20 seconds. Long work goes in batches and
 *    resumes on the next run (keep a cursor in Result data; read it back
 *    from $ctx->previous_data).
 * 4. run() should not throw for expected problems (API down, loopback
 *    blocked): return Status::INFO or WARN with a clear summary. If it does
 *    throw, the runner stores the run with status 'error'.
 * 5. Wrap WordPress reads you need to fake in tests behind constructor
 *    arguments (callables with real defaults). See CoreUpdates.
 * 6. Document the shape of Result::$data in the class docblock. The console
 *    (lane C) and the report snapshot builder (lane D1) read it.
 *
 * @since 0.1.0
 */
interface Check {

	/**
	 * Stable slug '<group>.<name>', e.g. 'updates.plugins'. Never changes once
	 * shipped: runs, findings and settings are keyed by it.
	 */
	public function id(): string;

	/**
	 * Group the check belongs to.
	 */
	public function group(): Group;

	/**
	 * Short translated label for the console, e.g. "WordPress core updates".
	 */
	public function label(): string;

	/**
	 * Default schedule. Engineers can override it per site in Settings.
	 */
	public function schedule(): Schedule;

	/**
	 * Inspect the site and return a result.
	 *
	 * @param Context $ctx The site being checked and run metadata.
	 */
	public function run( Context $ctx ): Result;
}
