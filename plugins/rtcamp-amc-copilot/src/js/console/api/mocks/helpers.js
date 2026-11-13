/**
 * Helpers shared by the mock fixtures. Everything is deterministic so the
 * screens and tests always see the same data.
 */

/**
 * Fixed "now" for all fixtures (site time, UTC).
 */
export const NOW = Date.UTC( 2026, 10, 13, 9, 0, 0 );

const HOUR = 3600 * 1000;
const DAY = 24 * HOUR;

/**
 * ISO 8601 UTC timestamp without milliseconds, like the REST API returns.
 *
 * @param {number} ms Epoch milliseconds.
 * @return {string} Timestamp, e.g. `2026-11-13T09:00:00Z`.
 */
export const iso = ( ms ) =>
	new Date( ms ).toISOString().replace( '.000Z', 'Z' );

/**
 * Timestamp `hours` before the fixture "now".
 *
 * @param {number} hours Hours ago.
 * @return {string} ISO 8601 timestamp.
 */
export const hoursAgo = ( hours ) => iso( NOW - hours * HOUR );

/**
 * Timestamp `days` before the fixture "now".
 *
 * @param {number} days Days ago.
 * @return {string} ISO 8601 timestamp.
 */
export const daysAgo = ( days ) => iso( NOW - days * DAY );

/**
 * Timestamp `hours` after the fixture "now".
 *
 * @param {number} hours Hours ahead.
 * @return {string} ISO 8601 timestamp.
 */
export const hoursAhead = ( hours ) => iso( NOW + hours * HOUR );

/**
 * Deterministic pseudo-random series (no Math.random, so fixtures are stable).
 *
 * @param {number} length   Number of points.
 * @param {number} base     Centre value.
 * @param {number} spread   Max deviation from the base.
 * @param {number} [seed=1] Seed.
 * @return {number[]} Integer series.
 */
export const series = ( length, base, spread, seed = 1 ) => {
	let state = seed * 9301 + 49297;
	return Array.from( { length }, () => {
		state = ( state * 9301 + 49297 ) % 233280;
		const unit = state / 233280 - 0.5;
		return Math.max( 0, Math.round( base + unit * 2 * spread ) );
	} );
};

/**
 * Deep clone for JSON-safe fixtures, so mutations never leak between calls.
 *
 * @param {Object} value Value to clone.
 * @return {Object} Clone.
 */
export const clone = ( value ) => JSON.parse( JSON.stringify( value ) );
