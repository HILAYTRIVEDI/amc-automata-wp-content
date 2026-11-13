/**
 * WordPress dependencies
 */
import { dateI18n } from '@wordpress/date';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Format an ISO timestamp as a site-localised date and time.
 *
 * @param {string|null|undefined} iso ISO 8601 timestamp.
 * @return {string} Formatted value, or an em dash when empty.
 */
export const formatDateTime = ( iso ) =>
	iso ? dateI18n( 'M j, Y H:i', iso ) : '—';

/**
 * Format an ISO timestamp or YYYY-MM-DD string as a site-localised date.
 *
 * @param {string|null|undefined} iso Date value.
 * @return {string} Formatted value, or an em dash when empty.
 */
export const formatDate = ( iso ) => ( iso ? dateI18n( 'M j, Y', iso ) : '—' );

/**
 * Format a byte count.
 *
 * @param {number} bytes Bytes.
 * @return {string} Human readable size.
 */
export const formatBytes = ( bytes ) => {
	if ( ! Number.isFinite( bytes ) ) {
		return '—';
	}
	const units = [ 'B', 'KB', 'MB', 'GB' ];
	let value = bytes;
	let unit = 0;
	while ( value >= 1024 && unit < units.length - 1 ) {
		value /= 1024;
		unit++;
	}
	return `${ unit === 0 ? value : value.toFixed( 1 ) } ${ units[ unit ] }`;
};

/**
 * Format a duration in milliseconds.
 *
 * @param {number} ms Milliseconds.
 * @return {string} "420 ms" or "3.4 s".
 */
export const formatMs = ( ms ) => {
	if ( ! Number.isFinite( ms ) || ms <= 0 ) {
		return '—';
	}
	return ms >= 1000 ? `${ ( ms / 1000 ).toFixed( 1 ) } s` : `${ ms } ms`;
};

/**
 * Format a percentage.
 *
 * @param {number} value Percent value (0 to 100).
 * @return {string} Formatted percent.
 */
export const formatPercent = ( value ) =>
	Number.isFinite( value ) ? `${ Number( value.toFixed( 1 ) ) }%` : '—';

/**
 * Label for a cron interval slug.
 *
 * @param {string} interval Interval slug.
 * @return {string} Label.
 */
export const intervalLabel = ( interval ) => {
	const labels = {
		hourly: __( 'Hourly', 'rtcamp-amc-copilot' ),
		twicedaily: __( 'Twice daily', 'rtcamp-amc-copilot' ),
		daily: __( 'Daily', 'rtcamp-amc-copilot' ),
		weekly: __( 'Weekly', 'rtcamp-amc-copilot' ),
		event: __( 'On event', 'rtcamp-amc-copilot' ),
	};
	return labels[ interval ] ?? interval;
};

/**
 * Plural-aware "n items" text.
 *
 * @param {number} count Count.
 * @return {string} Text.
 */
export const countItems = ( count ) =>
	sprintf(
		/* translators: %d: number of items. */
		_n( '%d item', '%d items', count, 'rtcamp-amc-copilot' ),
		count
	);
