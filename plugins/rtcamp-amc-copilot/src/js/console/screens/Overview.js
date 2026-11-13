/**
 * WordPress dependencies
 */
import { Card, CardBody, CardHeader, Notice } from '@wordpress/components';
import { useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getOverview } from '../api';
import DataTable from '../components/DataTable';
import RunNowButton from '../components/RunNowButton';
import ScreenState from '../components/ScreenState';
import StatusBadge, { statusFromSeverity } from '../components/StatusBadge';
import useResource from '../hooks/useResource';
import {
	customField,
	dateField,
	statusField,
	textField,
} from '../utils/fields';

const SEVERITIES = [ 'critical', 'high', 'medium', 'low', 'info' ];

const severityLabel = ( severity ) =>
	( {
		critical: __( 'Critical', 'rtcamp-amc-copilot' ),
		high: __( 'High', 'rtcamp-amc-copilot' ),
		medium: __( 'Medium', 'rtcamp-amc-copilot' ),
		low: __( 'Low', 'rtcamp-amc-copilot' ),
		info: __( 'Info', 'rtcamp-amc-copilot' ),
	} )[ severity ];

/**
 * Overview screen (`GET /overview`): overall status, open findings by
 * severity, last run per group, versions, counts and upcoming runs.
 *
 * @return {Element} Screen.
 */
export default function Overview() {
	const { data, error, isLoading, reload } = useResource( getOverview );

	const groupFields = useMemo(
		() => [
			textField( 'label', __( 'Group', 'rtcamp-amc-copilot' ) ),
			statusField( {
				status: ( item ) => item.status ?? 'info',
				render: ( { item } ) =>
					item.status ? (
						<StatusBadge status={ item.status } />
					) : (
						<span>
							{ __( 'Not run yet', 'rtcamp-amc-copilot' ) }
						</span>
					),
				filterBy: { operators: [ 'isAny' ] },
			} ),
			customField(
				'checks',
				__( 'Checks', 'rtcamp-amc-copilot' ),
				( item ) => item.checks,
				( item ) => <span>{ item.checks }</span>,
				{ type: 'integer' }
			),
			dateField( 'last_run_at', __( 'Last run', 'rtcamp-amc-copilot' ) ),
		],
		[]
	);

	const nextFields = useMemo(
		() => [
			textField( 'label', __( 'Check', 'rtcamp-amc-copilot' ) ),
			textField( 'check_id', __( 'ID', 'rtcamp-amc-copilot' ) ),
			dateField( 'next_run_at', __( 'Next run', 'rtcamp-amc-copilot' ) ),
		],
		[]
	);

	return (
		<ScreenState
			title={ __( 'Overview', 'rtcamp-amc-copilot' ) }
			actions={
				<RunNowButton
					checkIds="all"
					variant="primary"
					label={ __( 'Run all checks', 'rtcamp-amc-copilot' ) }
					onComplete={ reload }
				/>
			}
			isLoading={ isLoading }
			error={ error }
			onRetry={ reload }
			hasData={ !! data }
		>
			{ data && (
				<>
					{ data.kill_switch && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'The kill switch is on: capture and scheduled checks are stopped.',
								'rtcamp-amc-copilot'
							) }
						</Notice>
					) }
					<div className="amc-grid">
						<Card>
							<CardHeader>
								{ __( 'Overall status', 'rtcamp-amc-copilot' ) }
							</CardHeader>
							<CardBody>
								<StatusBadge status={ data.status } />
							</CardBody>
						</Card>
						<Card>
							<CardHeader>
								{ __(
									'Open findings by severity',
									'rtcamp-amc-copilot'
								) }
							</CardHeader>
							<CardBody>
								<ul className="amc-list">
									{ SEVERITIES.map( ( severity ) => (
										<li key={ severity }>
											<StatusBadge
												status={ statusFromSeverity(
													severity
												) }
												label={ severityLabel(
													severity
												) }
											/>{ ' ' }
											<strong>
												{ data.findings_by_severity[
													severity
												] ?? 0 }
											</strong>
										</li>
									) ) }
								</ul>
							</CardBody>
						</Card>
						<Card>
							<CardHeader>
								{ __( 'Versions', 'rtcamp-amc-copilot' ) }
							</CardHeader>
							<CardBody>
								<dl className="amc-dl">
									<dt>
										{ __(
											'WordPress',
											'rtcamp-amc-copilot'
										) }
									</dt>
									<dd>{ data.versions.wordpress }</dd>
									<dt>
										{ __( 'PHP', 'rtcamp-amc-copilot' ) }
									</dt>
									<dd>{ data.versions.php }</dd>
									<dt>
										{ __(
											'Database',
											'rtcamp-amc-copilot'
										) }
									</dt>
									<dd>{ data.versions.mysql }</dd>
									<dt>
										{ __( 'Theme', 'rtcamp-amc-copilot' ) }
									</dt>
									<dd>
										{ `${ data.versions.theme.name } ${ data.versions.theme.version }` }
										{ data.versions.theme.parent &&
											` (${ sprintf(
												/* translators: 1: parent theme name, 2: version. */
												__(
													'child of %1$s %2$s',
													'rtcamp-amc-copilot'
												),
												data.versions.theme.parent.name,
												data.versions.theme.parent
													.version
											) })` }
									</dd>
								</dl>
							</CardBody>
						</Card>
						<Card>
							<CardHeader>
								{ __( 'Counts', 'rtcamp-amc-copilot' ) }
							</CardHeader>
							<CardBody>
								<dl className="amc-dl">
									<dt>
										{ __(
											'Plugins',
											'rtcamp-amc-copilot'
										) }
									</dt>
									<dd>
										{ sprintf(
											/* translators: 1: total plugins, 2: active plugins. */
											__(
												'%1$d (%2$d active)',
												'rtcamp-amc-copilot'
											),
											data.counts.plugins,
											data.counts.plugins_active
										) }
									</dd>
									<dt>
										{ __( 'Themes', 'rtcamp-amc-copilot' ) }
									</dt>
									<dd>{ data.counts.themes }</dd>
									<dt>
										{ __(
											'Pending updates',
											'rtcamp-amc-copilot'
										) }
									</dt>
									<dd>
										{ sprintf(
											/* translators: 1: total, 2: core, 3: plugins, 4: themes. */
											__(
												'%1$d (core %2$d, plugins %3$d, themes %4$d)',
												'rtcamp-amc-copilot'
											),
											data.counts.pending_updates.total,
											data.counts.pending_updates.core,
											data.counts.pending_updates.plugins,
											data.counts.pending_updates.themes
										) }
									</dd>
								</dl>
							</CardBody>
						</Card>
					</div>

					<h3>
						{ __( 'Last run per group', 'rtcamp-amc-copilot' ) }
					</h3>
					<DataTable
						data={ data.groups }
						fields={ groupFields }
						getItemId={ ( item ) => item.group }
						exportName="amc-overview-groups"
						initialView={ { perPage: 12 } }
					/>

					<h3>
						{ __( 'Next scheduled runs', 'rtcamp-amc-copilot' ) }
					</h3>
					<p className="description">
						{ __(
							'Times are shown in site time.',
							'rtcamp-amc-copilot'
						) }
					</p>
					<DataTable
						data={ data.next_runs }
						fields={ nextFields }
						getItemId={ ( item ) => item.check_id }
						exportName="amc-overview-next-runs"
					/>
				</>
			) }
		</ScreenState>
	);
}
