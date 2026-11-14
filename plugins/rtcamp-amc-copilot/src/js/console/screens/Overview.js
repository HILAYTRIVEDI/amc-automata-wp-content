/**
 * WordPress dependencies
 */
import { Notice } from '@wordpress/components';
import { useMemo } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getOverview } from '../api';
import Postbox from '../components/Postbox';
import RunNowButton from '../components/RunNowButton';
import ScreenState from '../components/ScreenState';
import SimpleTable from '../components/SimpleTable';
import StatusBadge, {
	severityLabel,
	statusFromSeverity,
} from '../components/StatusBadge';
import useResource from '../hooks/useResource';
import {
	customField,
	dateField,
	statusField,
	textField,
} from '../utils/fields';
import { screenForGroup } from '../utils/findingScreen';
import { formatDateTime } from '../utils/format';
import { navigate, screenUrl } from '../utils/navigation';

const SEVERITIES = [ 'critical', 'high', 'medium', 'low', 'info' ];

/**
 * Headline for the overall status.
 *
 * @param {string}  status  pass | warn | fail | info.
 * @param {boolean} hasRuns Whether any check group has run.
 * @return {string} Headline.
 */
const headline = ( status, hasRuns ) => {
	if ( ! hasRuns ) {
		return __( 'No checks have run yet.', 'rtcamp-amc-copilot' );
	}
	return (
		{
			pass: __( 'All checks are passing.', 'rtcamp-amc-copilot' ),
			warn: __( 'Some checks need attention.', 'rtcamp-amc-copilot' ),
			fail: __( 'Some checks failed.', 'rtcamp-amc-copilot' ),
		}[ status ] ?? __( 'No problems found.', 'rtcamp-amc-copilot' )
	);
};

/**
 * Link to another console screen that switches without a reload.
 *
 * @param {Object}  props
 * @param {string}  props.screen   Screen slug.
 * @param {Element} props.children Link text.
 * @return {Element} Link.
 */
function ScreenLink( { screen, children } ) {
	return (
		<a
			href={ screenUrl( screen ) }
			onClick={ ( event ) => {
				if ( event.metaKey || event.ctrlKey || event.shiftKey ) {
					return;
				}
				event.preventDefault();
				navigate( screen );
			} }
		>
			{ children }
		</a>
	);
}

/**
 * Overview screen (`GET /overview`), laid out like Site Health and the
 * Dashboard: a status summary with open findings by severity, then the check
 * groups with their last run, and side boxes for versions and counts and the
 * next scheduled runs.
 *
 * @return {Element} Screen.
 */
export default function Overview() {
	const { data, error, isLoading, reload } = useResource( getOverview );

	const groupFields = useMemo(
		() => [
			textField( 'label', __( 'Group', 'rtcamp-amc-copilot' ), {
				render: ( { item } ) => {
					const screen = screenForGroup( item.group );
					return screen ? (
						<strong>
							<ScreenLink screen={ screen }>
								{ item.label }
							</ScreenLink>
						</strong>
					) : (
						<strong>{ item.label }</strong>
					);
				},
			} ),
			statusField( {
				status: ( item ) => item.status ?? 'info',
				render: ( { item } ) =>
					item.status ? (
						<StatusBadge status={ item.status } />
					) : (
						<span className="description">
							{ __( 'Not run yet', 'rtcamp-amc-copilot' ) }
						</span>
					),
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

	const ran = ( data?.groups ?? [] ).filter( ( group ) => group.status );
	const failing = ran.filter( ( group ) => 'fail' === group.status ).length;
	const warning = ran.filter( ( group ) => 'warn' === group.status ).length;
	const lastRun = ran.reduce(
		( latest, group ) =>
			! latest || group.last_run_at > latest ? group.last_run_at : latest,
		null
	);

	return (
		<ScreenState
			screen="overview"
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

					<Postbox
						className="amc-summary"
						title={ __( 'Site status', 'rtcamp-amc-copilot' ) }
					>
						<div className="amc-summary-body">
							<div className="amc-summary-status">
								<p className="amc-summary-headline">
									<StatusBadge status={ data.status } />
									<strong>
										{ headline(
											data.status,
											ran.length > 0
										) }
									</strong>
								</p>
								<p className="description">
									{ sprintf(
										/* translators: 1: groups that ran, 2: groups that failed, 3: groups with warnings. */
										_n(
											'%1$d check group ran: %2$d failed, %3$d with warnings.',
											'%1$d check groups ran: %2$d failed, %3$d with warnings.',
											ran.length,
											'rtcamp-amc-copilot'
										),
										ran.length,
										failing,
										warning
									) }{ ' ' }
									{ lastRun &&
										sprintf(
											/* translators: %s: date and time. */
											__(
												'Latest run %s.',
												'rtcamp-amc-copilot'
											),
											formatDateTime( lastRun )
										) }
								</p>
							</div>
							<div className="amc-summary-findings">
								<h4>
									{ __(
										'Open findings by severity',
										'rtcamp-amc-copilot'
									) }
								</h4>
								<ul className="amc-severity-list">
									{ SEVERITIES.map( ( severity ) => {
										const count =
											data.findings_by_severity[
												severity
											] ?? 0;
										const text = sprintf(
											/* translators: 1: number of findings, 2: severity, e.g. High. */
											__(
												'%1$d %2$s',
												'rtcamp-amc-copilot'
											),
											count,
											severityLabel( severity )
										);
										return (
											<li key={ severity }>
												{ count > 0 ? (
													<StatusBadge
														status={ statusFromSeverity(
															severity
														) }
														label={ text }
													/>
												) : (
													<span className="description">
														{ text }
													</span>
												) }
											</li>
										);
									} ) }
								</ul>
							</div>
						</div>
					</Postbox>

					<div className="amc-columns">
						<div className="amc-column-main">
							<Postbox
								title={ __(
									'Check groups',
									'rtcamp-amc-copilot'
								) }
							>
								<SimpleTable
									data={ data.groups }
									fields={ groupFields }
									getItemId={ ( item ) => item.group }
									exportName="amc-overview-groups"
									label={ __(
										'Last run per group',
										'rtcamp-amc-copilot'
									) }
								/>
							</Postbox>
						</div>
						<div className="amc-column-side">
							<Postbox
								title={ __(
									'At a glance',
									'rtcamp-amc-copilot'
								) }
							>
								<table className="widefat striped amc-kv">
									<caption className="screen-reader-text">
										{ __(
											'Versions and counts',
											'rtcamp-amc-copilot'
										) }
									</caption>
									<tbody>
										<tr>
											<th scope="row">
												{ __(
													'WordPress',
													'rtcamp-amc-copilot'
												) }
											</th>
											<td>{ data.versions.wordpress }</td>
										</tr>
										<tr>
											<th scope="row">
												{ __(
													'PHP',
													'rtcamp-amc-copilot'
												) }
											</th>
											<td>{ data.versions.php }</td>
										</tr>
										<tr>
											<th scope="row">
												{ __(
													'Database',
													'rtcamp-amc-copilot'
												) }
											</th>
											<td>{ data.versions.mysql }</td>
										</tr>
										<tr>
											<th scope="row">
												{ __(
													'Theme',
													'rtcamp-amc-copilot'
												) }
											</th>
											<td>
												{ `${ data.versions.theme.name } ${ data.versions.theme.version }` }
												{ data.versions.theme
													.parent && (
													<>
														<br />
														<span className="description">
															{ sprintf(
																/* translators: 1: parent theme name, 2: version. */
																__(
																	'Child of %1$s %2$s',
																	'rtcamp-amc-copilot'
																),
																data.versions
																	.theme
																	.parent
																	.name,
																data.versions
																	.theme
																	.parent
																	.version
															) }
														</span>
													</>
												) }
											</td>
										</tr>
										<tr>
											<th scope="row">
												{ __(
													'Plugins',
													'rtcamp-amc-copilot'
												) }
											</th>
											<td>
												{ sprintf(
													/* translators: 1: total plugins, 2: active plugins. */
													__(
														'%1$d (%2$d active)',
														'rtcamp-amc-copilot'
													),
													data.counts.plugins,
													data.counts.plugins_active
												) }
											</td>
										</tr>
										<tr>
											<th scope="row">
												{ __(
													'Themes',
													'rtcamp-amc-copilot'
												) }
											</th>
											<td>{ data.counts.themes }</td>
										</tr>
										<tr>
											<th scope="row">
												{ __(
													'Pending updates',
													'rtcamp-amc-copilot'
												) }
											</th>
											<td>
												<ScreenLink screen="updates">
													{
														data.counts
															.pending_updates
															.total
													}
												</ScreenLink>
												<br />
												<span className="description">
													{ sprintf(
														/* translators: 1: core, 2: plugins, 3: themes. */
														__(
															'Core %1$d, plugins %2$d, themes %3$d',
															'rtcamp-amc-copilot'
														),
														data.counts
															.pending_updates
															.core,
														data.counts
															.pending_updates
															.plugins,
														data.counts
															.pending_updates
															.themes
													) }
												</span>
											</td>
										</tr>
									</tbody>
								</table>
							</Postbox>
							<Postbox
								title={ __(
									'Next scheduled runs',
									'rtcamp-amc-copilot'
								) }
							>
								<SimpleTable
									data={ data.next_runs }
									fields={ nextFields }
									columns={ [ 'label', 'next_run_at' ] }
									getItemId={ ( item ) => item.check_id }
									exportName="amc-overview-next-runs"
									label={ __(
										'Next scheduled runs',
										'rtcamp-amc-copilot'
									) }
									limit={ 5 }
									emptyText={ __(
										'Nothing is scheduled.',
										'rtcamp-amc-copilot'
									) }
								/>
								<p className="description">
									{ __(
										'Times are shown in site time.',
										'rtcamp-amc-copilot'
									) }
								</p>
							</Postbox>
						</div>
					</div>
				</>
			) }
		</ScreenState>
	);
}
