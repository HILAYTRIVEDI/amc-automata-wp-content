/**
 * WordPress dependencies
 */
import { Button, Notice, Spinner } from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getNetworkSites, queueNetworkRun } from '../api';
import StatusBadge from '../components/StatusBadge';

/**
 * Network Sites overview screen for WordPress Multisite Super Admins.
 *
 * @return {Element} Network screen.
 */
export default function Network() {
	const [ loading, setLoading ] = useState( true );
	const [ sites, setSites ] = useState( [] );
	const [ error, setError ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ runningSiteId, setRunningSiteId ] = useState( null );
	const [ runningAll, setRunningAll ] = useState( false );

	const loadSites = useCallback( async () => {
		setLoading( true );
		setError( null );
		try {
			const res = await getNetworkSites( { per_page: 100 } );
			setSites( res.items || [] );
		} catch ( err ) {
			setError(
				err.message ||
					__( 'Failed to load network sites.', 'rtcamp-amc-copilot' )
			);
		} finally {
			setLoading( false );
		}
	}, [] );

	useEffect( () => {
		loadSites();
	}, [ loadSites ] );

	const handleRun = async ( blogId ) => {
		if ( 0 === blogId ) {
			setRunningAll( true );
		} else {
			setRunningSiteId( blogId );
		}
		setNotice( null );
		try {
			const res = await queueNetworkRun( {
				checkId: 'updates.plugins',
				blogId,
			} );
			setNotice( {
				type: 'success',
				message:
					0 === blogId
						? sprintf(
								/* translators: %d: number of sites */
								__(
									'Checks queued across %d network subsites.',
									'rtcamp-amc-copilot'
								),
								res.count || sites.length
							)
						: __(
								'Check run queued for this subsite.',
								'rtcamp-amc-copilot'
							),
			} );
			// Refresh list after brief moment
			setTimeout( loadSites, 1500 );
		} catch ( err ) {
			setNotice( {
				type: 'error',
				message:
					err.message ||
					__( 'Could not queue checks.', 'rtcamp-amc-copilot' ),
			} );
		} finally {
			setRunningAll( false );
			setRunningSiteId( null );
		}
	};

	const passCount = sites.filter( ( s ) => 'pass' === s.status ).length;
	const warnCount = sites.filter( ( s ) => 'warn' === s.status ).length;
	const failCount = sites.filter( ( s ) => 'fail' === s.status ).length;

	return (
		<div className="amc-screen amc-screen-network">
			<div className="amc-screen-header">
				<div>
					<h2>
						{ __(
							'Network Subsites Overview',
							'rtcamp-amc-copilot'
						) }
					</h2>
					<p className="description">
						{ __(
							'Monitor health, open findings, and run checks across all network sites from this central dashboard.',
							'rtcamp-amc-copilot'
						) }
					</p>
				</div>
				<div
					style={ {
						display: 'flex',
						gap: '8px',
						alignItems: 'center',
					} }
				>
					<Button
						variant="secondary"
						onClick={ loadSites }
						disabled={ loading }
					>
						{ __( 'Refresh', 'rtcamp-amc-copilot' ) }
					</Button>
					<Button
						variant="primary"
						onClick={ () => handleRun( 0 ) }
						isBusy={ runningAll }
						disabled={ runningAll || loading }
					>
						{ __(
							'Run Checks on All Sites',
							'rtcamp-amc-copilot'
						) }
					</Button>
				</div>
			</div>

			{ notice && (
				<Notice
					status={ notice.type }
					isDismissible
					onDismiss={ () => setNotice( null ) }
					style={ { marginTop: '16px' } }
				>
					{ notice.message }
				</Notice>
			) }

			{ error && (
				<Notice
					status="error"
					isDismissible={ false }
					style={ { marginTop: '16px' } }
				>
					{ error }
				</Notice>
			) }

			<div
				style={ {
					display: 'grid',
					gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))',
					gap: '16px',
					margin: '20px 0',
				} }
			>
				<div className="card" style={ { padding: '16px', margin: 0 } }>
					<div style={ { fontSize: '13px', color: '#646970' } }>
						{ __( 'Total Subsites', 'rtcamp-amc-copilot' ) }
					</div>
					<div
						style={ {
							fontSize: '24px',
							fontWeight: 'bold',
							marginTop: '4px',
						} }
					>
						{ sites.length }
					</div>
				</div>
				<div className="card" style={ { padding: '16px', margin: 0 } }>
					<div style={ { fontSize: '13px', color: '#646970' } }>
						{ __( 'Passing', 'rtcamp-amc-copilot' ) }
					</div>
					<div
						style={ {
							fontSize: '24px',
							fontWeight: 'bold',
							color: '#008a20',
							marginTop: '4px',
						} }
					>
						{ passCount }
					</div>
				</div>
				<div className="card" style={ { padding: '16px', margin: 0 } }>
					<div style={ { fontSize: '13px', color: '#646970' } }>
						{ __( 'Warnings', 'rtcamp-amc-copilot' ) }
					</div>
					<div
						style={ {
							fontSize: '24px',
							fontWeight: 'bold',
							color: '#dba617',
							marginTop: '4px',
						} }
					>
						{ warnCount }
					</div>
				</div>
				<div className="card" style={ { padding: '16px', margin: 0 } }>
					<div style={ { fontSize: '13px', color: '#646970' } }>
						{ __( 'Failing', 'rtcamp-amc-copilot' ) }
					</div>
					<div
						style={ {
							fontSize: '24px',
							fontWeight: 'bold',
							color: '#d63638',
							marginTop: '4px',
						} }
					>
						{ failCount }
					</div>
				</div>
			</div>

			{ loading && (
				<div style={ { padding: '32px', textAlign: 'center' } }>
					<Spinner />
				</div>
			) }

			{ ! loading && sites.length === 0 && (
				<p>{ __( 'No subsites found.', 'rtcamp-amc-copilot' ) }</p>
			) }

			{ ! loading && sites.length > 0 && (
				<table
					className="wp-list-table widefat striped"
					style={ { marginTop: '16px' } }
				>
					<thead>
						<tr>
							<th scope="col" style={ { width: '60px' } }>
								{ __( 'ID', 'rtcamp-amc-copilot' ) }
							</th>
							<th scope="col">
								{ __(
									'Site Name & URL',
									'rtcamp-amc-copilot'
								) }
							</th>
							<th scope="col" style={ { width: '120px' } }>
								{ __( 'Status', 'rtcamp-amc-copilot' ) }
							</th>
							<th scope="col">
								{ __( 'Open Findings', 'rtcamp-amc-copilot' ) }
							</th>
							<th scope="col">
								{ __( 'Latest Run', 'rtcamp-amc-copilot' ) }
							</th>
							<th
								scope="col"
								style={ { width: '220px', textAlign: 'right' } }
							>
								{ __( 'Actions', 'rtcamp-amc-copilot' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ sites.map( ( site ) => {
							const {
								critical = 0,
								high = 0,
								medium = 0,
								low = 0,
							} = site.findings || {};
							const hasFindings =
								critical > 0 ||
								high > 0 ||
								medium > 0 ||
								low > 0;
							return (
								<tr key={ site.id }>
									<td>
										<code>#{ site.id }</code>
									</td>
									<td>
										<strong>
											{ site.name || site.domain }
										</strong>
										<br />
										<a
											href={ site.site_url }
											target="_blank"
											rel="noreferrer"
											style={ { fontSize: '12px' } }
										>
											{ site.site_url }
										</a>
									</td>
									<td>
										<StatusBadge status={ site.status } />
									</td>
									<td>
										{ hasFindings ? (
											<div
												style={ {
													display: 'flex',
													gap: '6px',
													fontSize: '12px',
												} }
											>
												{ critical > 0 && (
													<span
														style={ {
															background:
																'#f8d7da',
															color: '#721c24',
															padding: '2px 6px',
															borderRadius: '3px',
														} }
													>
														{ critical }{ ' ' }
														{ __(
															'crit',
															'rtcamp-amc-copilot'
														) }
													</span>
												) }
												{ high > 0 && (
													<span
														style={ {
															background:
																'#fff3cd',
															color: '#856404',
															padding: '2px 6px',
															borderRadius: '3px',
														} }
													>
														{ high }{ ' ' }
														{ __(
															'high',
															'rtcamp-amc-copilot'
														) }
													</span>
												) }
												{ ( medium > 0 || low > 0 ) && (
													<span
														style={ {
															background:
																'#e2e3e5',
															color: '#383d41',
															padding: '2px 6px',
															borderRadius: '3px',
														} }
													>
														{ medium + low }{ ' ' }
														{ __(
															'med/low',
															'rtcamp-amc-copilot'
														) }
													</span>
												) }
											</div>
										) : (
											<span
												style={ {
													color: '#646970',
													fontSize: '12px',
												} }
											>
												{ __(
													'No open findings',
													'rtcamp-amc-copilot'
												) }
											</span>
										) }
									</td>
									<td>
										{ site.latest_run ? (
											<div style={ { fontSize: '12px' } }>
												<span>
													{ site.latest_run
														.check_label ||
														site.latest_run
															.check_id }
												</span>
												<br />
												<span
													style={ {
														color: '#646970',
													} }
												>
													{ site.latest_run.created_at
														? new Date(
																site.latest_run
																	.created_at
															).toLocaleString()
														: '—' }
												</span>
											</div>
										) : (
											<span
												style={ {
													color: '#646970',
													fontSize: '12px',
												} }
											>
												{ __(
													'No runs yet',
													'rtcamp-amc-copilot'
												) }
											</span>
										) }
									</td>
									<td style={ { textAlign: 'right' } }>
										<div
											style={ {
												display: 'flex',
												gap: '6px',
												justifyContent: 'flex-end',
											} }
										>
											<Button
												variant="secondary"
												size="small"
												onClick={ () =>
													handleRun( site.id )
												}
												isBusy={
													runningSiteId === site.id
												}
												disabled={
													runningSiteId === site.id ||
													runningAll
												}
											>
												{ __(
													'Run',
													'rtcamp-amc-copilot'
												) }
											</Button>
											<a
												href={ site.admin_url }
												className="button button-secondary button-small"
												target="_blank"
												rel="noreferrer"
											>
												{ __(
													'Console',
													'rtcamp-amc-copilot'
												) }
											</a>
										</div>
									</td>
								</tr>
							);
						} ) }
					</tbody>
				</table>
			) }
		</div>
	);
}
