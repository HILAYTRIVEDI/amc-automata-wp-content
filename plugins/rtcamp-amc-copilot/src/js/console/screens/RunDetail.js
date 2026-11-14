/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';
import { Button } from '@wordpress/components';
import { useCallback, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getRunDetail } from '../api';
import CheckData from '../components/CheckData';
import Postbox from '../components/Postbox';
import RunNowButton from '../components/RunNowButton';
import ScreenState from '../components/ScreenState';
import StatusBadge, {
	severityLabel,
	statusFromSeverity,
} from '../components/StatusBadge';
import useResource from '../hooks/useResource';
import { formatDateTime, formatMs, intervalLabel } from '../utils/format';
import { navigate, screenUrl } from '../utils/navigation';
import { describeValue, diffRunData } from '../utils/runDiff';

const BADGE = { error: 'warn', queued: 'info', running: 'info' };

const runStatusLabels = () => ( {
	pass: __( 'Pass', 'rtcamp-amc-copilot' ),
	warn: __( 'Warning', 'rtcamp-amc-copilot' ),
	fail: __( 'Failed', 'rtcamp-amc-copilot' ),
	info: __( 'Info', 'rtcamp-amc-copilot' ),
	error: __( 'Error (check threw)', 'rtcamp-amc-copilot' ),
	queued: __( 'Queued', 'rtcamp-amc-copilot' ),
	running: __( 'Running', 'rtcamp-amc-copilot' ),
} );

const triggerLabels = () => ( {
	cron: __( 'Schedule (WP-Cron)', 'rtcamp-amc-copilot' ),
	manual: __( 'Manual (console)', 'rtcamp-amc-copilot' ),
	cli: __( 'WP-CLI', 'rtcamp-amc-copilot' ),
} );

const findingStatusLabels = () => ( {
	open: __( 'Open', 'rtcamp-amc-copilot' ),
	resolved: __( 'Resolved', 'rtcamp-amc-copilot' ),
	accepted: __( 'Accepted', 'rtcamp-amc-copilot' ),
} );

/**
 * Status badge for a run status (adds queued, running and error).
 *
 * @param {Object} props
 * @param {string} props.status Run status.
 * @return {Element} Badge.
 */
export const RunStatus = ( { status } ) => (
	<StatusBadge
		status={ BADGE[ status ] ?? status }
		label={ runStatusLabels()[ status ] ?? status }
	/>
);

/**
 * A link to another run that switches the page without reloading.
 *
 * @param {Object}  props
 * @param {number}  props.id       Run id.
 * @param {Element} props.children Link text.
 * @return {Element} Link.
 */
function RunLink( { id, children } ) {
	return (
		<a
			href={ screenUrl( 'runs', { run: id } ) }
			onClick={ ( event ) => {
				if ( event.metaKey || event.ctrlKey || event.shiftKey ) {
					return;
				}
				event.preventDefault();
				navigate( 'runs', { run: id } );
			} }
		>
			{ children }
		</a>
	);
}

/**
 * Label/value rows as a core table.
 *
 * @param {Object}                   props
 * @param {Array<[string, Element]>} props.rows    Rows.
 * @param {string}                   props.caption Table caption (screen readers).
 * @return {Element} Table.
 */
function KeyValues( { rows, caption } ) {
	return (
		<table className="widefat striped amc-kv">
			<caption className="screen-reader-text">{ caption }</caption>
			<tbody>
				{ rows.map( ( [ label, value ] ) => (
					<tr key={ label }>
						<th scope="row">{ label }</th>
						<td>{ value }</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

/**
 * Copy text to the clipboard and say so.
 *
 * @param {string} text Text.
 */
const copyText = async ( text ) => {
	try {
		await window.navigator.clipboard.writeText( text );
		speak( __( 'Copied to the clipboard.', 'rtcamp-amc-copilot' ) );
	} catch {
		speak(
			__(
				'Could not copy. Select the JSON and copy it instead.',
				'rtcamp-amc-copilot'
			),
			'assertive'
		);
	}
};

/**
 * Save text as a file.
 *
 * @param {string} filename File name.
 * @param {string} text     Contents.
 */
const downloadJson = ( filename, text ) => {
	const blob = new window.Blob( [ text ], { type: 'application/json' } );
	const url = window.URL.createObjectURL( blob );
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = filename;
	document.body.appendChild( link );
	link.click();
	document.body.removeChild( link );
	window.URL.revokeObjectURL( url );
};

/**
 * What changed in the data since the previous result of the same check.
 *
 * @param {Object}      props
 * @param {Object}      props.run      This run.
 * @param {Object|null} props.previous Previous result, with data.
 * @return {Element} Box.
 */
function ChangesSincePrevious( { run, previous } ) {
	const changes = useMemo(
		() => ( previous ? diffRunData( previous.data, run.data ) : [] ),
		[ previous, run.data ]
	);
	const kinds = {
		added: __( 'Added', 'rtcamp-amc-copilot' ),
		removed: __( 'Removed', 'rtcamp-amc-copilot' ),
		changed: __( 'Changed', 'rtcamp-amc-copilot' ),
	};

	return (
		<Postbox
			className="amc-run-diff"
			title={ __(
				'Changes since the previous result',
				'rtcamp-amc-copilot'
			) }
		>
			{ ! previous && (
				<p>
					{ __(
						'This is the first result of this check, so there is nothing to compare with.',
						'rtcamp-amc-copilot'
					) }
				</p>
			) }
			{ previous && (
				<p className="description">
					{ sprintf(
						/* translators: %s: date and time. */
						__(
							'Compared with the result from %s:',
							'rtcamp-amc-copilot'
						),
						formatDateTime(
							previous.finished_at ?? previous.created_at
						)
					) }{ ' ' }
					<RunLink id={ previous.id }>
						{ sprintf(
							/* translators: %d: run id. */
							__( 'Run #%d', 'rtcamp-amc-copilot' ),
							previous.id
						) }
					</RunLink>{ ' ' }
					<RunStatus status={ previous.status } />
				</p>
			) }
			{ previous && 0 === changes.length && (
				<p>
					{ __(
						'No change: the data is the same as the previous result.',
						'rtcamp-amc-copilot'
					) }
				</p>
			) }
			{ changes.length > 0 && (
				<table className="widefat striped">
					<caption className="screen-reader-text">
						{ __( 'Changed values', 'rtcamp-amc-copilot' ) }
					</caption>
					<thead>
						<tr>
							<th scope="col">
								{ __( 'Field', 'rtcamp-amc-copilot' ) }
							</th>
							<th scope="col">
								{ __( 'Change', 'rtcamp-amc-copilot' ) }
							</th>
							<th scope="col">
								{ __( 'Before', 'rtcamp-amc-copilot' ) }
							</th>
							<th scope="col">
								{ __( 'Now', 'rtcamp-amc-copilot' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ changes.map( ( change ) => (
							<tr key={ change.path }>
								<td>
									<code>{ change.path }</code>
								</td>
								<td>{ kinds[ change.kind ] }</td>
								<td>{ describeValue( change.before ) }</td>
								<td>{ describeValue( change.after ) }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</Postbox>
	);
}

/**
 * Run detail page (`?screen=runs&run=<id>`): everything needed to debug one
 * run of a check: when and how it ran, why it failed, what it found, its
 * data (readable and raw), what changed since the previous result, and the
 * check's recent runs.
 *
 * @param {Object} props
 * @param {number} props.runId Run id.
 * @return {Element} Screen.
 */
export default function RunDetail( { runId } ) {
	const fetcher = useCallback( () => getRunDetail( runId ), [ runId ] );
	const { data: run, error, isLoading, reload } = useResource( fetcher );
	const [ showRaw, setShowRaw ] = useState( false );

	const json = useMemo(
		() => ( run ? JSON.stringify( run.data ?? {}, null, 2 ) : '' ),
		[ run ]
	);

	const title = run
		? sprintf(
				/* translators: 1: run id, 2: check name. */
				__( 'Run #%1$d: %2$s', 'rtcamp-amc-copilot' ),
				runId,
				run.check_label
			)
		: sprintf(
				/* translators: %d: run id. */
				__( 'Run #%d', 'rtcamp-amc-copilot' ),
				runId
			);

	const isError = 'error' === run?.status;
	const isPending = [ 'queued', 'running' ].includes( run?.status );

	const actions = (
		<>
			<Button variant="tertiary" onClick={ () => navigate( 'runs' ) }>
				{ __( '← Run history', 'rtcamp-amc-copilot' ) }
			</Button>
			{ run && (
				<RunNowButton
					rerunId={ run.id }
					label={ __( 'Re-run this check', 'rtcamp-amc-copilot' ) }
					onComplete={ ( runs ) => {
						if ( runs?.[ 0 ]?.id ) {
							navigate( 'runs', { run: runs[ 0 ].id } );
						}
					} }
				/>
			) }
		</>
	);

	return (
		<ScreenState
			title={ title }
			description={ run?.summary }
			actions={ actions }
			isLoading={ isLoading }
			error={ error }
			onRetry={ reload }
			hasData={ !! run }
		>
			{ run && (
				<div className="amc-run-detail">
					<nav
						className="amc-run-pager"
						aria-label={ __(
							'Other runs of this check',
							'rtcamp-amc-copilot'
						) }
					>
						{ run.older_id ? (
							<RunLink id={ run.older_id }>
								{ __( '← Older run', 'rtcamp-amc-copilot' ) }
							</RunLink>
						) : (
							<span className="description">
								{ __( 'No older run', 'rtcamp-amc-copilot' ) }
							</span>
						) }
						{ run.newer_id ? (
							<RunLink id={ run.newer_id }>
								{ __( 'Newer run →', 'rtcamp-amc-copilot' ) }
							</RunLink>
						) : (
							<span className="description">
								{ __(
									'This is the newest run',
									'rtcamp-amc-copilot'
								) }
							</span>
						) }
					</nav>

					{ isPending && (
						<div className="notice notice-info inline">
							<p>
								{ __(
									'This run has not finished yet. Reload the page to see its result.',
									'rtcamp-amc-copilot'
								) }{ ' ' }
								<Button variant="link" onClick={ reload }>
									{ __( 'Reload', 'rtcamp-amc-copilot' ) }
								</Button>
							</p>
						</div>
					) }

					{ isError && (
						<Postbox
							className="amc-run-error"
							title={ __(
								'Why the check failed',
								'rtcamp-amc-copilot'
							) }
						>
							<p>
								{ __(
									'The check threw an error, so no result or findings were stored. Fix the cause and re-run the check.',
									'rtcamp-amc-copilot'
								) }
							</p>
							<KeyValues
								caption={ __(
									'Error details',
									'rtcamp-amc-copilot'
								) }
								rows={ [
									[
										__( 'Error', 'rtcamp-amc-copilot' ),
										<code key="e">
											{ run.data?.exception ?? '—' }
										</code>,
									],
									[
										__( 'Message', 'rtcamp-amc-copilot' ),
										run.data?.message ?? '—',
									],
									[
										__( 'Where', 'rtcamp-amc-copilot' ),
										run.data?.file ? (
											<code key="w">
												{ `${ run.data.file }:${ run.data.line }` }
											</code>
										) : (
											'—'
										),
									],
								] }
							/>
						</Postbox>
					) }

					<div className="amc-columns">
						<Postbox
							title={ __(
								'About this run',
								'rtcamp-amc-copilot'
							) }
						>
							<KeyValues
								caption={ __(
									'About this run',
									'rtcamp-amc-copilot'
								) }
								rows={ [
									[
										__( 'Status', 'rtcamp-amc-copilot' ),
										<RunStatus
											key="s"
											status={ run.status }
										/>,
									],
									[
										__( 'Check', 'rtcamp-amc-copilot' ),
										<>
											{ run.check_label }{ ' ' }
											<code>{ run.check_id }</code>
										</>,
									],
									[
										__( 'Group', 'rtcamp-amc-copilot' ),
										run.group_label ?? run.group ?? '—',
									],
									[
										__( 'Trigger', 'rtcamp-amc-copilot' ),
										triggerLabels()[ run.trigger ] ??
											run.trigger,
									],
									[
										__(
											'Started by',
											'rtcamp-amc-copilot'
										),
										run.user?.name ??
											__(
												'The site (no user)',
												'rtcamp-amc-copilot'
											),
									],
									[
										__( 'Schedule', 'rtcamp-amc-copilot' ),
										run.schedule
											? intervalLabel( run.schedule )
											: '—',
									],
								] }
							/>
						</Postbox>
						<Postbox title={ __( 'Timing', 'rtcamp-amc-copilot' ) }>
							<KeyValues
								caption={ __( 'Timing', 'rtcamp-amc-copilot' ) }
								rows={ [
									[
										__( 'Queued', 'rtcamp-amc-copilot' ),
										formatDateTime( run.created_at ),
									],
									[
										__( 'Started', 'rtcamp-amc-copilot' ),
										formatDateTime( run.started_at ),
									],
									[
										__( 'Finished', 'rtcamp-amc-copilot' ),
										formatDateTime( run.finished_at ),
									],
									[
										__(
											'Waited in queue',
											'rtcamp-amc-copilot'
										),
										0 === run.queue_ms
											? __(
													'No wait',
													'rtcamp-amc-copilot'
												)
											: formatMs( run.queue_ms ),
									],
									[
										__( 'Duration', 'rtcamp-amc-copilot' ),
										formatMs( run.duration_ms ),
									],
								] }
							/>
						</Postbox>
					</div>

					<Postbox
						title={ sprintf(
							/* translators: %d: number of findings. */
							_n(
								'Findings from this run (%d)',
								'Findings from this run (%d)',
								run.findings?.length ?? 0,
								'rtcamp-amc-copilot'
							),
							run.findings?.length ?? 0
						) }
					>
						{ run.findings?.length ? (
							<table className="widefat striped">
								<caption className="screen-reader-text">
									{ __(
										'Findings from this run',
										'rtcamp-amc-copilot'
									) }
								</caption>
								<thead>
									<tr>
										<th scope="col">
											{ __(
												'Severity',
												'rtcamp-amc-copilot'
											) }
										</th>
										<th scope="col">
											{ __(
												'Finding',
												'rtcamp-amc-copilot'
											) }
										</th>
										<th scope="col">
											{ __(
												'Status',
												'rtcamp-amc-copilot'
											) }
										</th>
										<th scope="col">
											{ __(
												'First seen',
												'rtcamp-amc-copilot'
											) }
										</th>
										<th scope="col">
											{ __(
												'Key',
												'rtcamp-amc-copilot'
											) }
										</th>
									</tr>
								</thead>
								<tbody>
									{ run.findings.map( ( finding ) => (
										<tr key={ finding.id }>
											<td>
												<StatusBadge
													status={ statusFromSeverity(
														finding.severity
													) }
													label={ severityLabel(
														finding.severity
													) }
												/>
											</td>
											<td>{ finding.title }</td>
											<td>
												{ findingStatusLabels()[
													finding.status
												] ?? finding.status }
											</td>
											<td>
												{ formatDateTime(
													finding.first_seen
												) }
											</td>
											<td>
												<code>
													{ finding.dedupe_key }
												</code>
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						) : (
							<p>
								{ isError
									? __(
											'None: the check failed before it could report findings.',
											'rtcamp-amc-copilot'
										)
									: __(
											'This run raised no findings.',
											'rtcamp-amc-copilot'
										) }
							</p>
						) }
					</Postbox>

					{ ! isError && ! isPending && (
						<ChangesSincePrevious
							run={ run }
							previous={ run.previous }
						/>
					) }

					<Postbox
						className="amc-run-data"
						title={ __( 'Result data', 'rtcamp-amc-copilot' ) }
						actions={
							<>
								<Button
									variant="secondary"
									size="small"
									onClick={ () => copyText( json ) }
								>
									{ __( 'Copy JSON', 'rtcamp-amc-copilot' ) }
								</Button>
								<Button
									variant="secondary"
									size="small"
									onClick={ () =>
										downloadJson(
											`amc-run-${ run.id }-${ run.check_id }.json`,
											JSON.stringify( run, null, 2 )
										)
									}
								>
									{ __(
										'Download run',
										'rtcamp-amc-copilot'
									) }
								</Button>
							</>
						}
					>
						{ ! isError && (
							<CheckData
								data={ run.data ?? {} }
								prefix={ `amc-run-${ run.id }` }
								title={ run.check_label }
							/>
						) }
						{ ! isError && (
							<p>
								<Button
									variant="link"
									aria-expanded={ showRaw }
									aria-controls="amc-run-json"
									onClick={ () => setShowRaw( ( v ) => ! v ) }
								>
									{ showRaw
										? __(
												'Hide raw JSON',
												'rtcamp-amc-copilot'
											)
										: __(
												'Show raw JSON',
												'rtcamp-amc-copilot'
											) }
								</Button>
							</p>
						) }
						{ ( showRaw || isError ) && (
							<pre
								id="amc-run-json"
								className="amc-json"
								tabIndex={ 0 }
								role="region"
								aria-label={ __(
									'Run data (JSON)',
									'rtcamp-amc-copilot'
								) }
							>
								{ json }
							</pre>
						) }
					</Postbox>

					<Postbox
						title={ __(
							'Recent runs of this check',
							'rtcamp-amc-copilot'
						) }
						actions={
							<a
								href={ screenUrl( 'runs', {
									check: run.check_id,
								} ) }
								onClick={ ( event ) => {
									event.preventDefault();
									navigate( 'runs', {
										check: run.check_id,
									} );
								} }
							>
								{ __(
									'All runs of this check',
									'rtcamp-amc-copilot'
								) }
							</a>
						}
					>
						<table className="widefat striped">
							<caption className="screen-reader-text">
								{ __(
									'Recent runs of this check',
									'rtcamp-amc-copilot'
								) }
							</caption>
							<thead>
								<tr>
									<th scope="col">
										{ __( 'Run', 'rtcamp-amc-copilot' ) }
									</th>
									<th scope="col">
										{ __( 'Status', 'rtcamp-amc-copilot' ) }
									</th>
									<th scope="col">
										{ __(
											'Trigger',
											'rtcamp-amc-copilot'
										) }
									</th>
									<th scope="col">
										{ __(
											'Started',
											'rtcamp-amc-copilot'
										) }
									</th>
									<th scope="col">
										{ __(
											'Duration',
											'rtcamp-amc-copilot'
										) }
									</th>
									<th scope="col">
										{ __(
											'Summary',
											'rtcamp-amc-copilot'
										) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ ( run.history ?? [] ).map( ( item ) => (
									<tr
										key={ item.id }
										className={
											item.id === run.id
												? 'amc-run-current'
												: undefined
										}
										aria-current={
											item.id === run.id
												? 'true'
												: undefined
										}
									>
										<td>
											{ item.id === run.id ? (
												<strong>{ `#${ item.id }` }</strong>
											) : (
												<RunLink id={ item.id }>
													{ `#${ item.id }` }
												</RunLink>
											) }
										</td>
										<td>
											<RunStatus status={ item.status } />
										</td>
										<td>
											{ triggerLabels()[ item.trigger ] ??
												item.trigger }
										</td>
										<td>
											{ formatDateTime(
												item.started_at ??
													item.created_at
											) }
										</td>
										<td>
											{ formatMs( item.duration_ms ) }
										</td>
										<td>
											<span className="amc-cell-wrap">
												{ item.summary }
											</span>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</Postbox>
				</div>
			) }
		</ScreenState>
	);
}
