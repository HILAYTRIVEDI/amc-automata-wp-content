/**
 * Report Editor Extension.
 *
 * Adds a right-hand sidebar panel in the block editor for `amc_report` posts
 * that acts as a Table of Contents and highlights sections with failures/issues.
 *
 * @package
 */

/* eslint-disable import/no-unresolved */
import { registerPlugin } from '@wordpress/plugins';
import {
	PluginDocumentSettingPanel,
	PluginPrePublishPanel,
} from '@wordpress/editor';
/* eslint-enable import/no-unresolved */
import { Button, Modal, RadioControl } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { useEffect, useMemo, useState, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { caution, envelope as emailIcon } from '@wordpress/icons';

/**
 * Report sections definition in standard report order.
 */
const REPORT_SECTIONS = [
	{
		key: 'overview',
		title: __( 'Overview', 'rtcamp-amc-copilot' ),
		block: 'amc/section-overview',
	},
	{
		key: 'cms',
		title: __( 'CMS', 'rtcamp-amc-copilot' ),
		block: 'amc/section-cms',
	},
	{
		key: 'theme',
		title: __( 'Theme', 'rtcamp-amc-copilot' ),
		block: 'amc/section-theme',
	},
	{
		key: 'plugins_themes',
		title: __( 'Plugins and themes', 'rtcamp-amc-copilot' ),
		block: 'amc/section-plugins-themes',
	},
	{
		key: 'database_backups',
		title: __( 'Database backups', 'rtcamp-amc-copilot' ),
		block: 'amc/section-database-backups',
	},
	{
		key: 'user_management',
		title: __( 'User management', 'rtcamp-amc-copilot' ),
		block: 'amc/section-user-management',
	},
	{
		key: 'hosting',
		title: __( 'Hosting', 'rtcamp-amc-copilot' ),
		block: 'amc/section-hosting',
	},
	{
		key: 'domains',
		title: __( 'Domains', 'rtcamp-amc-copilot' ),
		block: 'amc/section-domains',
	},
	{
		key: 'ssl',
		title: __( 'SSL', 'rtcamp-amc-copilot' ),
		block: 'amc/section-ssl',
	},
	{
		key: 'caching_cdn',
		title: __( 'Caching and CDN', 'rtcamp-amc-copilot' ),
		block: 'amc/section-caching-cdn',
	},
	{
		key: 'security',
		title: __( 'Security', 'rtcamp-amc-copilot' ),
		block: 'amc/section-security',
	},
	{
		key: 'forms',
		title: __( 'Forms', 'rtcamp-amc-copilot' ),
		block: 'amc/section-forms',
	},
	{
		key: 'analytics',
		title: __( 'Analytics and tagging', 'rtcamp-amc-copilot' ),
		block: 'amc/section-analytics',
	},
	{
		key: 'accessibility',
		title: __( 'Accessibility', 'rtcamp-amc-copilot' ),
		block: 'amc/section-accessibility',
	},
	{
		key: 'php_errors',
		title: __( 'PHP errors', 'rtcamp-amc-copilot' ),
		block: 'amc/section-php-errors',
	},
	{
		key: 'smoke_checks',
		title: __( 'Smoke checks', 'rtcamp-amc-copilot' ),
		block: 'amc/section-smoke-checks',
	},
	{
		key: 'performance',
		title: __( 'Performance', 'rtcamp-amc-copilot' ),
		block: 'amc/section-performance',
	},
	{
		key: 'engineer_notes',
		title: __( "Engineer's notes", 'rtcamp-amc-copilot' ),
		block: 'amc/section-engineer-notes',
	},
];

/**
 * URL of the console screen for an issue section.
 *
 * @param {string} section Section key.
 * @return {string} Admin URL.
 */
function getConsoleScreenUrl( section ) {
	const map = {
		security: 'security',
		ssl: 'security',
		vulnerabilities: 'vulnerabilities',
		php_errors: 'errors',
		smoke_checks: 'smoke',
		performance: 'performance',
		plugins_themes: 'updates',
		hosting: 'inventory',
	};
	const screen = map[ section ] || 'security';
	return `admin.php?page=amc-copilot&screen=${ screen }`;
}

/**
 * Client-side issue detector from snapshot data.
 *
 * @param {Object} snapshot Snapshot data.
 * @return {Array} List of detected issues.
 */
function detectIssuesFromSnapshot( snapshot ) {
	if ( ! snapshot || ! snapshot.sections ) {
		return [];
	}

	const issues = [];
	const sections = snapshot.sections;

	// 1. Security vulnerabilities.
	const vulns = sections.security?.vulnerabilities;
	if ( Array.isArray( vulns ) ) {
		vulns.forEach( ( vuln ) => {
			if ( vuln && vuln.status !== 'resolved' && ! vuln.resolved_at ) {
				const name =
					vuln.component ||
					vuln.reference ||
					__( 'Component', 'rtcamp-amc-copilot' );
				const sev = ( vuln.severity || 'high' ).toUpperCase();
				issues.push( {
					section: 'security',
					section_title: __( 'Security', 'rtcamp-amc-copilot' ),
					block: 'amc/section-security',
					severity: 'fail',
					url: getConsoleScreenUrl( 'vulnerabilities' ),
					title: sprintf(
						/* translators: 1: Vulnerability component name, 2: CVE or reference. */
						__(
							'Open Vulnerability: %1$s (%2$s)',
							'rtcamp-amc-copilot'
						),
						name,
						vuln.reference || ''
					),
					message: sprintf(
						/* translators: %s: Severity level. */
						__(
							'Unresolved vulnerability with %s severity.',
							'rtcamp-amc-copilot'
						),
						sev
					),
				} );
			}
		} );
	}

	// 2. Core checksums.
	const checksums = sections.security?.core_checksums;
	if ( checksums ) {
		const modified = checksums.modified_files || [];
		if ( checksums.status === 'fail' || modified.length > 0 ) {
			issues.push( {
				section: 'security',
				section_title: __( 'Security', 'rtcamp-amc-copilot' ),
				block: 'amc/section-security',
				severity: 'fail',
				url: getConsoleScreenUrl( 'security' ),
				title: __(
					'Modified WordPress Core Files',
					'rtcamp-amc-copilot'
				),
				message: sprintf(
					/* translators: %d: Modified files count. */
					__(
						'%d official core file(s) failed checksum check.',
						'rtcamp-amc-copilot'
					),
					modified.length
				),
			} );
		}
	}

	// 3. Security configuration checks.
	const configs = sections.security?.config_checks;
	if ( Array.isArray( configs ) ) {
		configs.forEach( ( checkItem ) => {
			if ( checkItem && checkItem.status === 'fail' ) {
				issues.push( {
					section: 'security',
					section_title: __( 'Security', 'rtcamp-amc-copilot' ),
					block: 'amc/section-security',
					severity: 'fail',
					url: getConsoleScreenUrl( 'security' ),
					title: sprintf(
						/* translators: %s: Security check label. */
						__(
							'Security Config Failure: %s',
							'rtcamp-amc-copilot'
						),
						checkItem.label || checkItem.id || ''
					),
					message: __(
						'Security check flagged a critical risk.',
						'rtcamp-amc-copilot'
					),
				} );
			}
		} );
	}

	// 4. PHP Fatal Errors.
	const fatalCount = Number( sections.php_errors?.fatal_count || 0 );
	if ( fatalCount > 0 ) {
		issues.push( {
			section: 'php_errors',
			section_title: __( 'PHP errors', 'rtcamp-amc-copilot' ),
			block: 'amc/section-php-errors',
			severity: 'fail',
			url: getConsoleScreenUrl( 'php_errors' ),
			title: __( 'PHP Fatal Errors Detected', 'rtcamp-amc-copilot' ),
			message: sprintf(
				/* translators: %d: Number of fatal errors. */
				__(
					'%d fatal error(s) logged in reporting period.',
					'rtcamp-amc-copilot'
				),
				fatalCount
			),
		} );
	}

	// 5. Smoke checks.
	const smokeUrls = sections.smoke_checks?.urls;
	if ( Array.isArray( smokeUrls ) ) {
		smokeUrls.forEach( ( item ) => {
			if ( item && item.url ) {
				const passRate =
					item.pass_rate !== undefined
						? Number( item.pass_rate )
						: 100;
				if ( passRate < 100 ) {
					issues.push( {
						section: 'smoke_checks',
						section_title: __(
							'Smoke checks',
							'rtcamp-amc-copilot'
						),
						block: 'amc/section-smoke-checks',
						severity: 'fail',
						url: getConsoleScreenUrl( 'smoke_checks' ),
						title: sprintf(
							/* translators: %s: Checked URL. */
							__(
								'Smoke Check Failure: %s',
								'rtcamp-amc-copilot'
							),
							item.url
						),
						message: sprintf(
							/* translators: 1: Pass rate, 2: Run count. */
							__(
								'Pass rate dropped to %1$s%% (%2$d runs).',
								'rtcamp-amc-copilot'
							),
							String( passRate ),
							Number( item.runs || 0 )
						),
					} );
				}
			}
		} );
	}

	// 6. SSL.
	const ssl = sections.ssl;
	if ( ssl ) {
		if ( ssl.https === false ) {
			issues.push( {
				section: 'ssl',
				section_title: __( 'SSL', 'rtcamp-amc-copilot' ),
				block: 'amc/section-ssl',
				severity: 'fail',
				url: getConsoleScreenUrl( 'ssl' ),
				title: __( 'Site Not Running on HTTPS', 'rtcamp-amc-copilot' ),
				message: __(
					'Website URL is not served with encrypted HTTPS.',
					'rtcamp-amc-copilot'
				),
			} );
		}

		if ( ssl.days_left !== null && ssl.days_left !== undefined ) {
			const days = Number( ssl.days_left );
			if ( days <= 0 ) {
				issues.push( {
					section: 'ssl',
					section_title: __( 'SSL', 'rtcamp-amc-copilot' ),
					block: 'amc/section-ssl',
					severity: 'fail',
					url: getConsoleScreenUrl( 'ssl' ),
					title: __(
						'SSL Certificate Expired',
						'rtcamp-amc-copilot'
					),
					message: __(
						'The SSL certificate has expired.',
						'rtcamp-amc-copilot'
					),
				} );
			} else if ( days <= 15 ) {
				issues.push( {
					section: 'ssl',
					section_title: __( 'SSL', 'rtcamp-amc-copilot' ),
					block: 'amc/section-ssl',
					severity: 'fail',
					url: getConsoleScreenUrl( 'ssl' ),
					title: __(
						'SSL Certificate Expiring Soon',
						'rtcamp-amc-copilot'
					),
					message: sprintf(
						/* translators: %d: Days until expiration. */
						__(
							'Certificate expires in %d days.',
							'rtcamp-amc-copilot'
						),
						days
					),
				} );
			}
		}
	}

	return issues;
}

/**
 * Report Table of Contents & Issues Panel Component.
 *
 * @return {Element|null} Component element.
 */
function ReportTocPanel() {
	const postType = useSelect(
		( select ) => select( 'core/editor' ).getCurrentPostType(),
		[]
	);
	const [ filterIssuesOnly, setFilterIssuesOnly ] = useState( false );

	const meta = useSelect( ( select ) => {
		return select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
	}, [] );

	const blocks = useSelect( ( select ) => {
		return select( 'core/block-editor' ).getBlocks() || [];
	}, [] );

	const { selectBlock } = useDispatch( 'core/block-editor' );

	// Parse issues from post meta, or compute dynamically from snapshot.
	const issues = useMemo( () => {
		if ( meta._amc_issues ) {
			try {
				const parsed = JSON.parse( meta._amc_issues );
				if ( Array.isArray( parsed ) && parsed.length > 0 ) {
					return parsed;
				}
			} catch {}
		}

		if ( meta._amc_snapshot ) {
			try {
				const snapshot = JSON.parse( meta._amc_snapshot );
				return detectIssuesFromSnapshot( snapshot );
			} catch {}
		}

		return [];
	}, [ meta._amc_issues, meta._amc_snapshot ] );

	// Group issues by section.
	const issuesBySection = useMemo( () => {
		const map = {};
		issues.forEach( ( issue ) => {
			if ( ! map[ issue.section ] ) {
				map[ issue.section ] = [];
			}
			map[ issue.section ].push( issue );
		} );
		return map;
	}, [ issues ] );

	// Map section blocks to their client IDs in the editor canvas.
	const blockMap = useMemo( () => {
		const map = {};
		blocks.forEach( ( block ) => {
			map[ block.name ] = block.clientId;
		} );
		return map;
	}, [ blocks ] );

	// Scroll and select block in canvas.
	const scrollToSection = useCallback(
		( blockName ) => {
			const clientId = blockMap[ blockName ];
			if ( ! clientId ) {
				return;
			}

			selectBlock( clientId );

			const findElement = () => {
				let el =
					document.getElementById( `block-${ clientId }` ) ||
					document.querySelector( `[data-block="${ clientId }"]` );
				if ( el ) {
					return el;
				}

				const iframes = document.querySelectorAll(
					'iframe[name="editor-canvas"]'
				);
				for ( const iframe of iframes ) {
					try {
						const doc =
							iframe.contentDocument ||
							iframe.contentWindow?.document;
						if ( doc ) {
							el =
								doc.getElementById( `block-${ clientId }` ) ||
								doc.querySelector(
									`[data-block="${ clientId }"]`
								) ||
								doc.querySelector(
									`[data-type="${ blockName }"]`
								);
							if ( el ) {
								return el;
							}
						}
					} catch {}
				}
				return null;
			};

			setTimeout( () => {
				const target = findElement();
				if ( target ) {
					target.scrollIntoView( {
						behavior: 'smooth',
						block: 'center',
					} );
					target.classList.add( 'amc-highlight-pulse' );
					setTimeout( () => {
						target.classList.remove( 'amc-highlight-pulse' );
					}, 2000 );
				}
			}, 50 );
		},
		[ blockMap, selectBlock ]
	);

	const totalIssues = issues.length;
	const failedSectionsCount = Object.keys( issuesBySection ).length;

	// Jump to first issue.
	const jumpToFirstIssue = useCallback( () => {
		if ( issues.length > 0 ) {
			scrollToSection( issues[ 0 ].block );
		}
	}, [ issues, scrollToSection ] );

	const displayedSections = useMemo( () => {
		if ( filterIssuesOnly ) {
			return REPORT_SECTIONS.filter(
				( sec ) => ( issuesBySection[ sec.key ] || [] ).length > 0
			);
		}
		return REPORT_SECTIONS;
	}, [ filterIssuesOnly, issuesBySection ] );

	if ( postType !== 'amc_report' ) {
		return null;
	}

	return (
		<PluginDocumentSettingPanel
			name="amc-report-toc-panel"
			title={
				totalIssues > 0
					? sprintf(
							/* translators: %d: Number of detected issues. */
							__(
								'Table of Contents (%d Issues)',
								'rtcamp-amc-copilot'
							),
							totalIssues
						)
					: __( 'Report Table of Contents', 'rtcamp-amc-copilot' )
			}
			className="amc-report-toc-panel"
		>
			{ totalIssues > 0 ? (
				<div className="amc-toc-alert amc-toc-alert--fail">
					<div className="amc-toc-alert__header">
						<span
							className="amc-toc-alert__icon"
							aria-hidden="true"
						>
							●
						</span>
						<strong>
							{ sprintf(
								/* translators: 1: Issue count, 2: Section count. */
								__(
									'%1$d Issue(s) flagged across %2$d section(s)',
									'rtcamp-amc-copilot'
								),
								totalIssues,
								failedSectionsCount
							) }
						</strong>
					</div>
					<p className="amc-toc-alert__description">
						{ __(
							'These sections need engineer review before publishing.',
							'rtcamp-amc-copilot'
						) }
					</p>
					<Button
						variant="secondary"
						isSmall
						className="amc-toc-alert__btn"
						onClick={ jumpToFirstIssue }
					>
						{ __( 'Jump to first issue →', 'rtcamp-amc-copilot' ) }
					</Button>
				</div>
			) : (
				<div className="amc-toc-alert amc-toc-alert--pass">
					<div className="amc-toc-alert__header">
						<span
							className="amc-toc-alert__icon"
							aria-hidden="true"
						>
							✓
						</span>
						<strong>
							{ __( 'All Checks Passed', 'rtcamp-amc-copilot' ) }
						</strong>
					</div>
					<p className="amc-toc-alert__description">
						{ __(
							'No failures flagged in this maintenance report.',
							'rtcamp-amc-copilot'
						) }
					</p>
				</div>
			) }

			{ totalIssues > 0 && (
				<div className="amc-toc-filters">
					<Button
						isSmall
						variant={ ! filterIssuesOnly ? 'primary' : 'tertiary' }
						onClick={ () => setFilterIssuesOnly( false ) }
					>
						{ sprintf(
							/* translators: %d: Total sections count. */
							__( 'All (%d)', 'rtcamp-amc-copilot' ),
							REPORT_SECTIONS.length
						) }
					</Button>
					<Button
						isSmall
						variant={ filterIssuesOnly ? 'primary' : 'tertiary' }
						onClick={ () => setFilterIssuesOnly( true ) }
					>
						{ sprintf(
							/* translators: %d: Failed sections count. */
							__( 'Issues only (%d)', 'rtcamp-amc-copilot' ),
							failedSectionsCount
						) }
					</Button>
				</div>
			) }

			<nav
				className="amc-toc-list"
				aria-label={ __( 'Report sections', 'rtcamp-amc-copilot' ) }
			>
				{ displayedSections.map( ( section ) => {
					const sectionIssues = issuesBySection[ section.key ] || [];
					const hasFailures = sectionIssues.length > 0;
					const isBlockPresent = Boolean( blockMap[ section.block ] );

					return (
						<div
							key={ section.key }
							className={ `amc-toc-item ${
								hasFailures
									? 'amc-toc-item--fail'
									: 'amc-toc-item--pass'
							}` }
						>
							<div className="amc-toc-item__header">
								<button
									type="button"
									className="amc-toc-item__link"
									disabled={ ! isBlockPresent }
									onClick={ () =>
										scrollToSection( section.block )
									}
								>
									<span
										className={ `amc-toc-dot ${
											hasFailures
												? 'amc-toc-dot--fail'
												: 'amc-toc-dot--pass'
										}` }
									/>
									<span className="amc-toc-item__title">
										{ section.title }
									</span>
								</button>

								{ hasFailures ? (
									<span className="amc-toc-tag amc-toc-tag--fail">
										{ sprintf(
											/* translators: %d: Issues count. */
											__(
												'%d issue(s)',
												'rtcamp-amc-copilot'
											),
											sectionIssues.length
										) }
									</span>
								) : (
									<span className="amc-toc-tag amc-toc-tag--pass">
										{ __( 'Pass', 'rtcamp-amc-copilot' ) }
									</span>
								) }
							</div>

							{ hasFailures && (
								<ul className="amc-toc-issues">
									{ sectionIssues.map( ( issue, idx ) => (
										<li
											key={ idx }
											className="amc-toc-issue"
										>
											<span className="amc-toc-issue__bullet">
												•
											</span>
											<div className="amc-toc-issue__content">
												<a
													href={
														issue.url &&
														! issue.url.includes(
															'screen=overview'
														)
															? issue.url
															: getConsoleScreenUrl(
																	issue.section
																)
													}
													target="_blank"
													rel="noopener noreferrer"
													className="amc-toc-issue__link"
													title={ __(
														'Open issue details in a new tab',
														'rtcamp-amc-copilot'
													) }
												>
													<span className="amc-toc-issue__title">
														{ issue.title }
													</span>
													<span
														className="amc-toc-issue__ext"
														aria-hidden="true"
													>
														{ ' ↗' }
													</span>
												</a>
												{ issue.message && (
													<span className="amc-toc-issue__msg">
														{ issue.message }
													</span>
												) }
											</div>
										</li>
									) ) }
								</ul>
							) }
						</div>
					);
				} ) }
			</nav>
		</PluginDocumentSettingPanel>
	);
}

/**
 * Publish confirmation modal and pre-publish panel for Maintenance Reports.
 *
 * @return {Element|null} Plugin component.
 */
function ReportPublishPlugin() {
	const postType = useSelect(
		( select ) => select( 'core/editor' ).getCurrentPostType(),
		[]
	);
	const { isPrePublishOpened, postStatus, postMeta } = useSelect(
		( select ) => {
			const editor = select( 'core/editor' );
			return {
				isPrePublishOpened: editor.isPrePublishSidebarOpened
					? editor.isPrePublishSidebarOpened()
					: false,
				postStatus: editor.getEditedPostAttribute( 'status' ),
				postMeta: editor.getEditedPostAttribute( 'meta' ) || {},
			};
		},
		[]
	);

	const { editPost, savePost } = useDispatch( 'core/editor' );

	const recipients = useMemo( () => {
		if (
			window.amcReportEditor &&
			Array.isArray( window.amcReportEditor.recipients )
		) {
			return window.amcReportEditor.recipients;
		}
		return [];
	}, [] );

	const sendPublishEmail =
		typeof postMeta._amc_send_publish_email === 'boolean'
			? postMeta._amc_send_publish_email
			: ( window.amcReportEditor?.sendPublishEmail ?? true );

	const [ showModal, setShowModal ] = useState( false );
	const [ modalDismissed, setModalDismissed ] = useState( false );

	// When pre-publish sidebar opens on a draft report, trigger the confirmation modal pop-up.
	useEffect( () => {
		if (
			isPrePublishOpened &&
			'publish' !== postStatus &&
			! modalDismissed
		) {
			setShowModal( true );
		}
		if ( ! isPrePublishOpened ) {
			setModalDismissed( false );
		}
	}, [ isPrePublishOpened, postStatus, modalDismissed ] );

	const handleSetSendEmail = ( val ) => {
		editPost( { meta: { _amc_send_publish_email: val } } );
	};

	if ( postType !== 'amc_report' ) {
		return null;
	}

	return (
		<>
			<PluginPrePublishPanel
				id="amc-report-publish-notification-panel"
				title={ __(
					'Client Email Notification',
					'rtcamp-amc-copilot'
				) }
				initialOpen={ true }
			>
				<p
					style={ {
						fontSize: '13px',
						lineHeight: 1.5,
						color: '#334155',
						margin: '0 0 12px',
					} }
				>
					{ __(
						'Send this maintenance report to registered client emails upon publishing?',
						'rtcamp-amc-copilot'
					) }
				</p>
				<RadioControl
					selected={ sendPublishEmail ? 'yes' : 'no' }
					options={ [
						{
							label: __(
								'Yes, send report to client upon publish',
								'rtcamp-amc-copilot'
							),
							value: 'yes',
						},
						{
							label: __(
								'No, publish only (do not send email)',
								'rtcamp-amc-copilot'
							),
							value: 'no',
						},
					] }
					onChange={ ( val ) => handleSetSendEmail( val === 'yes' ) }
				/>

				<div
					style={ {
						background: '#f8fafc',
						border: '1px solid #e2e8f0',
						borderRadius: '6px',
						padding: '10px 12px',
						marginTop: '12px',
						fontSize: '12px',
					} }
				>
					<strong
						style={ {
							display: 'block',
							marginBottom: '6px',
							color: '#1e293b',
						} }
					>
						{ __(
							'Registered recipient emails:',
							'rtcamp-amc-copilot'
						) }
					</strong>
					{ recipients.length > 0 ? (
						<ul
							style={ {
								margin: 0,
								paddingLeft: '16px',
								color: '#334155',
								lineHeight: 1.5,
							} }
						>
							{ recipients.map( ( email ) => (
								<li key={ email }>{ email }</li>
							) ) }
						</ul>
					) : (
						<span style={ { color: '#dc2626' } }>
							{ __(
								'No client recipients registered in AMC Settings.',
								'rtcamp-amc-copilot'
							) }
						</span>
					) }
				</div>

				<div style={ { marginTop: '12px' } }>
					<Button
						variant="secondary"
						isSmall
						onClick={ () => setShowModal( true ) }
					>
						{ __(
							'Open publish confirmation pop-up',
							'rtcamp-amc-copilot'
						) }
					</Button>
				</div>
			</PluginPrePublishPanel>

			{ showModal && (
				<Modal
					title={ __(
						'Send report to client?',
						'rtcamp-amc-copilot'
					) }
					onRequestClose={ () => {
						setShowModal( false );
						setModalDismissed( true );
					} }
					className="amc-publish-confirm-modal"
				>
					<p
						style={ {
							fontSize: '14px',
							lineHeight: 1.5,
							color: '#334155',
							margin: '0 0 16px',
						} }
					>
						{ __(
							'Would you like to send this maintenance report to the client upon publishing?',
							'rtcamp-amc-copilot'
						) }
					</p>

					<RadioControl
						selected={ sendPublishEmail ? 'yes' : 'no' }
						options={ [
							{
								label: __(
									'Yes, send report to registered client emails',
									'rtcamp-amc-copilot'
								),
								value: 'yes',
							},
							{
								label: __(
									'No, publish only (do not send email)',
									'rtcamp-amc-copilot'
								),
								value: 'no',
							},
						] }
						onChange={ ( val ) =>
							handleSetSendEmail( val === 'yes' )
						}
					/>

					<div
						style={ {
							background: '#f8fafc',
							border: '1px solid #e2e8f0',
							borderRadius: '6px',
							padding: '12px',
							margin: '16px 0 20px',
							fontSize: '12px',
						} }
					>
						<strong
							style={ {
								display: 'block',
								marginBottom: '6px',
								color: '#1e293b',
							} }
						>
							{ __(
								'Registered recipient emails:',
								'rtcamp-amc-copilot'
							) }
						</strong>
						{ recipients.length > 0 ? (
							<ul
								style={ {
									margin: 0,
									paddingLeft: '16px',
									color: '#334155',
									lineHeight: 1.6,
								} }
							>
								{ recipients.map( ( email ) => (
									<li key={ email }>{ email }</li>
								) ) }
							</ul>
						) : (
							<span style={ { color: '#dc2626' } }>
								{ __(
									'No client recipients registered in AMC Settings.',
									'rtcamp-amc-copilot'
								) }
							</span>
						) }
					</div>

					<div
						style={ {
							display: 'flex',
							justifyContent: 'flex-end',
							gap: '8px',
						} }
					>
						<Button
							variant="tertiary"
							onClick={ () => {
								setShowModal( false );
								setModalDismissed( true );
							} }
						>
							{ __( 'Cancel', 'rtcamp-amc-copilot' ) }
						</Button>
						<Button
							variant="secondary"
							onClick={ () => {
								handleSetSendEmail( false );
								setShowModal( false );
								setModalDismissed( true );
								editPost( { status: 'publish' } );
								savePost();
							} }
						>
							{ __( 'No, Publish Only', 'rtcamp-amc-copilot' ) }
						</Button>
						<Button
							variant="primary"
							onClick={ () => {
								handleSetSendEmail( true );
								setShowModal( false );
								setModalDismissed( true );
								editPost( { status: 'publish' } );
								savePost();
							} }
						>
							{ __(
								'Yes, Send & Publish',
								'rtcamp-amc-copilot'
							) }
						</Button>
					</div>
				</Modal>
			) }
		</>
	);
}

// Register editor plugins.
registerPlugin( 'amc-report-toc', {
	render: ReportTocPanel,
	icon: caution,
} );

registerPlugin( 'amc-report-publish', {
	render: ReportPublishPlugin,
	icon: emailIcon,
} );
