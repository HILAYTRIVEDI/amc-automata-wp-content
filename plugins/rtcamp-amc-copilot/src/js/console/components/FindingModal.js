/**
 * WordPress dependencies
 */
import { Modal, Notice, Spinner } from '@wordpress/components';
import { useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getFinding, getRun } from '../api';
import useResource from '../hooks/useResource';
import { formatDateTime } from '../utils/format';
import { ErrorNotice } from './ScreenState';
import FindingLink from './FindingLink';
import StatusBadge, { severityLabel, statusFromSeverity } from './StatusBadge';

const STATE_LABELS = () => ( {
	open: __( 'Open', 'rtcamp-amc-copilot' ),
	accepted: __( 'Accepted risk', 'rtcamp-amc-copilot' ),
	muted: __( 'Muted', 'rtcamp-amc-copilot' ),
	resolved: __( 'Resolved', 'rtcamp-amc-copilot' ),
} );

/**
 * Loads a finding and the run that produced it.
 *
 * @param {number} id Finding id.
 * @return {Promise<{finding: Object, run: Object|null}>} Finding and run.
 */
const load = async ( id ) => {
	const finding = await getFinding( id );
	const run = await getRun( finding.run_id ).catch( () => null );
	return { finding, run };
};

/**
 * Modal for one finding, opened by `?finding=<id>` on any screen (the alert
 * emails link to `?page=amc-copilot&screen=findings&finding=<id>`). Shows the
 * finding and the run behind it, with the run's summary and a link to its
 * raw data on the Runs screen.
 *
 * @param {Object}                          props
 * @param {number}                          props.findingId Finding id.
 * @param {(...args: unknown[]) => unknown} props.onClose   Close handler.
 * @return {Element} Modal.
 */
export default function FindingModal( { findingId, onClose } ) {
	const fetcher = useCallback( () => load( findingId ), [ findingId ] );
	const { data, error, isLoading } = useResource( fetcher );

	return (
		<Modal
			title={ sprintf(
				/* translators: %d: finding id. */
				__( 'Finding #%d', 'rtcamp-amc-copilot' ),
				findingId
			) }
			onRequestClose={ onClose }
			size="large"
		>
			{ isLoading && ! data && (
				<p role="status">
					<Spinner />{ ' ' }
					<span className="screen-reader-text">
						{ __( 'Loading the finding…', 'rtcamp-amc-copilot' ) }
					</span>
				</p>
			) }
			{ error && <ErrorNotice error={ error } /> }
			{ data && (
				<>
					<h2>{ data.finding.title }</h2>
					<dl className="amc-dl">
						<dt>{ __( 'Severity', 'rtcamp-amc-copilot' ) }</dt>
						<dd>
							<StatusBadge
								status={ statusFromSeverity(
									data.finding.severity
								) }
								label={ severityLabel( data.finding.severity ) }
							/>
						</dd>
						<dt>{ __( 'State', 'rtcamp-amc-copilot' ) }</dt>
						<dd>
							{ STATE_LABELS()[ data.finding.status ] ??
								data.finding.status }
						</dd>
						<dt>{ __( 'Check', 'rtcamp-amc-copilot' ) }</dt>
						<dd>{ data.finding.check_id }</dd>
						<dt>{ __( 'First seen', 'rtcamp-amc-copilot' ) }</dt>
						<dd>{ formatDateTime( data.finding.first_seen ) }</dd>
						<dt>{ __( 'Last seen', 'rtcamp-amc-copilot' ) }</dt>
						<dd>{ formatDateTime( data.finding.last_seen ) }</dd>
						{ data.finding.note && (
							<>
								<dt>{ __( 'Note', 'rtcamp-amc-copilot' ) }</dt>
								<dd>{ data.finding.note }</dd>
							</>
						) }
					</dl>

					<h3>
						{ __( 'Run that produced it', 'rtcamp-amc-copilot' ) }
					</h3>
					{ data.run ? (
						<>
							<p>
								<StatusBadge status={ data.run.status } />{ ' ' }
								{ data.run.summary }
							</p>
							<p className="description">
								{ sprintf(
									/* translators: 1: run id, 2: date and time. */
									__(
										'Run #%1$d, %2$s.',
										'rtcamp-amc-copilot'
									),
									data.run.id,
									formatDateTime( data.run.created_at )
								) }{ ' ' }
								<FindingLink
									runId={ data.run.id }
									label={ __(
										'Show the run data',
										'rtcamp-amc-copilot'
									) }
								/>
							</p>
						</>
					) : (
						<Notice status="info" isDismissible={ false }>
							{ __(
								'The run behind this finding is no longer available.',
								'rtcamp-amc-copilot'
							) }
						</Notice>
					) }

					<h3>{ __( 'Finding data', 'rtcamp-amc-copilot' ) }</h3>
					<pre
						className="amc-json"
						tabIndex={ 0 }
						role="region"
						aria-label={ __(
							'Finding data (JSON)',
							'rtcamp-amc-copilot'
						) }
					>
						{ JSON.stringify( data.finding.data, null, 2 ) }
					</pre>
				</>
			) }
		</Modal>
	);
}
