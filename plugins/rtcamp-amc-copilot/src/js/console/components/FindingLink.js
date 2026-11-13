/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { navigate, screenUrl } from '../utils/navigation';

/**
 * Link from a finding to the run that produced it, so an engineer can see the
 * raw data behind the claim (opens the Runs screen with that run's data).
 *
 * @param {Object} props
 * @param {number} props.runId   Run ID.
 * @param {string} [props.label] Link text; defaults to "Run #ID".
 * @return {Element|null} Link.
 */
export default function FindingLink( { runId, label } ) {
	if ( ! runId ) {
		return <span>{ '—' }</span>;
	}
	const text =
		label ??
		sprintf(
			/* translators: %d: run ID. */
			__( 'Run #%d', 'rtcamp-amc-copilot' ),
			runId
		);
	return (
		<a
			href={ screenUrl( 'runs', { run: runId } ) }
			onClick={ ( event ) => {
				if ( event.metaKey || event.ctrlKey || event.shiftKey ) {
					return;
				}
				event.preventDefault();
				navigate( 'runs', { run: runId } );
			} }
		>
			{ text }
			<span className="screen-reader-text">
				{ ' ' }
				{ __( '(shows the run data)', 'rtcamp-amc-copilot' ) }
			</span>
		</a>
	);
}
