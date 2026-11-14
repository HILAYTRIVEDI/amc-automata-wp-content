/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { useCallback, useState } from '@wordpress/element';
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
 * Browser storage key for which check panels are open.
 */
export const PANELS_KEY = 'amc-copilot:open-panels';

/**
 * Whether a panel starts open: results that need attention (failed, warning,
 * the check threw) open; passing, informational and never-run ones closed.
 *
 * @param {Object|null} result `{ run, data }`, or null.
 * @return {boolean} Open by default.
 */
export const openByDefault = ( result ) =>
	[ 'fail', 'warn', 'error' ].includes( result?.run?.status );

/**
 * Saved open/closed choices, `{ check_id: boolean }`. Storage can be
 * blocked (private windows), so every access is guarded.
 *
 * @return {Object} Choices.
 */
const readSaved = () => {
	try {
		return JSON.parse( window.localStorage.getItem( PANELS_KEY ) ) ?? {};
	} catch {
		return {};
	}
};

/**
 * Save open/closed choices.
 *
 * @param {Object} choices `{ check_id: boolean }`.
 */
const writeSaved = ( choices ) => {
	try {
		window.localStorage.setItem( PANELS_KEY, JSON.stringify( choices ) );
	} catch {
		// Not saved: the panels still work for this page view.
	}
};

/**
 * The latest result of one check: name, status and summary in the header
 * (visible even when closed), and when it ran, a link to the run behind it
 * and the result's data in the collapsible body.
 *
 * @param {Object}                    props
 * @param {string}                    props.checkId    Check id.
 * @param {Object|null}               props.result     `{ run, data }` from the screen endpoint, or null if the check never ran.
 * @param {boolean}                   [props.isOpen]   Whether the details are shown (defaults to {@link openByDefault}).
 * @param {(isOpen: boolean) => void} [props.onToggle] Called with the new state.
 * @return {Element} Box.
 */
export default function CheckPanel( { checkId, result, isOpen, onToggle } ) {
	const title = result?.run.check_label ?? checkId;
	const [ ownOpen, setOwnOpen ] = useState( () => openByDefault( result ) );
	const open = isOpen ?? ownOpen;

	return (
		<Postbox
			className="amc-check-panel"
			title={ title }
			collapsible
			isOpen={ open }
			onToggle={ onToggle ?? setOwnOpen }
			subtitle={
				result
					? result.run.summary
					: __( 'Not run yet', 'rtcamp-amc-copilot' )
			}
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
 * Panels for a list of checks, in order, with "Expand all" and "Collapse
 * all". Each panel opens by default when its result needs attention, and the
 * engineer's own choice is remembered in this browser.
 *
 * @param {Object}   props
 * @param {string[]} props.ids    Check ids.
 * @param {Object}   props.checks `{ check_id: result|null }` from the screen endpoint.
 * @return {Element} Panels.
 */
export function CheckPanels( { ids, checks } ) {
	const [ saved, setSaved ] = useState( readSaved );

	const isOpen = ( id ) =>
		saved[ id ] ?? openByDefault( checks[ id ] ?? null );

	const update = useCallback( ( changes ) => {
		setSaved( ( current ) => {
			const next = { ...current, ...changes };
			writeSaved( next );
			return next;
		} );
	}, [] );

	const setAll = ( open ) =>
		update( Object.fromEntries( ids.map( ( id ) => [ id, open ] ) ) );

	const openCount = ids.filter( isOpen ).length;

	return (
		<>
			{ ids.length > 1 && (
				<p className="amc-panels-toggle">
					<Button
						variant="link"
						onClick={ () => setAll( true ) }
						disabled={ openCount === ids.length }
					>
						{ __( 'Expand all', 'rtcamp-amc-copilot' ) }
					</Button>
					<span aria-hidden="true">{ ' | ' }</span>
					<Button
						variant="link"
						onClick={ () => setAll( false ) }
						disabled={ 0 === openCount }
					>
						{ __( 'Collapse all', 'rtcamp-amc-copilot' ) }
					</Button>
				</p>
			) }
			{ ids.map( ( id ) => (
				<CheckPanel
					key={ id }
					checkId={ id }
					result={ checks[ id ] ?? null }
					isOpen={ isOpen( id ) }
					onToggle={ ( open ) => update( { [ id ]: open } ) }
				/>
			) ) }
		</>
	);
}
