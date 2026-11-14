/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatDateTime } from '../utils/format';
import CheckData from './CheckData';
import FindingLink from './FindingLink';
import Postbox from './Postbox';
import StatusBadge from './StatusBadge';

const STATUS = { error: 'warn', queued: 'info', running: 'info' };

/**
 * The latest result of one check: name, status, summary, when it ran, a link
 * to the run behind it, and the result's data.
 *
 * @param {Object}      props
 * @param {string}      props.checkId Check id.
 * @param {Object|null} props.result  `{ run, data }` from the screen endpoint, or null if the check never ran.
 * @return {Element} Box.
 */
export default function CheckPanel( { checkId, result } ) {
	const title = result?.run.check_label ?? checkId;

	return (
		<Postbox
			className="amc-check-panel"
			title={ title }
			actions={
				result ? (
					<StatusBadge
						status={
							STATUS[ result.run.status ] ?? result.run.status
						}
					/>
				) : (
					<span className="description">
						{ __( 'Not run yet', 'rtcamp-amc-copilot' ) }
					</span>
				)
			}
		>
			{ result ? (
				<>
					<p className="amc-check-summary">{ result.run.summary }</p>
					<p className="description">
						{ sprintf(
							/* translators: %s: date and time. */
							__( 'Last run %s.', 'rtcamp-amc-copilot' ),
							formatDateTime(
								result.run.finished_at ?? result.run.created_at
							)
						) }{ ' ' }
						<FindingLink runId={ result.run.id } />
					</p>
					<CheckData
						data={ result.data }
						prefix={ `amc-${ checkId }` }
						title={ title }
					/>
				</>
			) : (
				<p>
					{ __(
						'This check has not run yet. Run it, or wait for its schedule.',
						'rtcamp-amc-copilot'
					) }
				</p>
			) }
		</Postbox>
	);
}

/**
 * Panels for a list of checks, in order.
 *
 * @param {Object}   props
 * @param {string[]} props.ids    Check ids.
 * @param {Object}   props.checks `{ check_id: result|null }` from the screen endpoint.
 * @return {Element} Panels.
 */
export function CheckPanels( { ids, checks } ) {
	return (
		<>
			{ ids.map( ( id ) => (
				<CheckPanel
					key={ id }
					checkId={ id }
					result={ checks[ id ] ?? null }
				/>
			) ) }
		</>
	);
}
