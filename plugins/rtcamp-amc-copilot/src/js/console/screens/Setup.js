/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';
import {
	Button,
	Card,
	CardBody,
	CardFooter,
	CardHeader,
	CheckboxControl,
	Notice,
	RadioControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	completeSetup,
	getSavedFields,
	getSettings,
	getSetup,
	runCheck,
	saveSavedFields,
	saveSettings,
} from '../api';
import ScreenState, { ErrorNotice } from '../components/ScreenState';
import useResource from '../hooks/useResource';
import { formatDate } from '../utils/format';
import { navigate, screenUrl } from '../utils/navigation';

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const DATE = /^\d{4}-\d{2}-\d{2}$/;

/**
 * Wizard steps, in order.
 *
 * @return {Array<{slug: string, label: string, description: string}>} Steps.
 */
export const getSteps = () => [
	{
		slug: 'environment',
		label: __( 'Welcome', 'rtcamp-amc-copilot' ),
		description: __(
			'AMC Copilot runs maintenance checks, emails alerts and drafts the monthly Website Maintenance Report. Start by telling it what kind of site this is.',
			'rtcamp-amc-copilot'
		),
	},
	{
		slug: 'site',
		label: __( 'Client and site', 'rtcamp-amc-copilot' ),
		description: __(
			'Shown at the top of every report. These carry forward from report to report.',
			'rtcamp-amc-copilot'
		),
	},
	{
		slug: 'hosting',
		label: __( 'Hosting and backups', 'rtcamp-amc-copilot' ),
		description: __(
			'What the plugin detected is filled in already. Check it and add what it cannot see.',
			'rtcamp-amc-copilot'
		),
	},
	{
		slug: 'domains',
		label: __( 'Domains, SSL and accessibility', 'rtcamp-amc-copilot' ),
		description: __(
			'Facts for the Domains, SSL and Accessibility sections of the report.',
			'rtcamp-amc-copilot'
		),
	},
	{
		slug: 'notifications',
		label: __( 'Notifications', 'rtcamp-amc-copilot' ),
		description: __(
			'Who gets alert emails and the daily digest.',
			'rtcamp-amc-copilot'
		),
	},
	{
		slug: 'reports',
		label: __( 'Report schedule', 'rtcamp-amc-copilot' ),
		description: __(
			'Draft reports are generated automatically; an engineer reviews and publishes them.',
			'rtcamp-amc-copilot'
		),
	},
	{
		slug: 'review',
		label: __( 'Review', 'rtcamp-amc-copilot' ),
		description: __(
			'Check the answers, then finish. You can change any of them later in Settings.',
			'rtcamp-amc-copilot'
		),
	},
];

/**
 * Saved report fields asked for on each step: `[ section, field, label, help ]`.
 *
 * @return {Object<string, Array<Array<string>>>} Fields per step slug.
 */
export const getStepFields = () => ( {
	site: [
		[
			'overview',
			'client_name',
			__( 'Client name', 'rtcamp-amc-copilot' ),
			__( 'As it should appear on reports.', 'rtcamp-amc-copilot' ),
		],
		[
			'overview',
			'maintenance_schedule',
			__( 'Maintenance schedule', 'rtcamp-amc-copilot' ),
			__( 'For example Monthly or Quarterly.', 'rtcamp-amc-copilot' ),
		],
		[
			'cms',
			'websites_covered',
			__( 'Websites covered', 'rtcamp-amc-copilot' ),
			__(
				'Every site this maintenance contract covers.',
				'rtcamp-amc-copilot'
			),
		],
		[
			'theme',
			'theme_origin',
			__( 'Theme origin', 'rtcamp-amc-copilot' ),
			__(
				'For example Custom, Marketplace or Block theme from WordPress.org.',
				'rtcamp-amc-copilot'
			),
		],
	],
	hosting: [
		[
			'hosting',
			'hosting_type',
			__( 'Hosting type', 'rtcamp-amc-copilot' ),
			__(
				'For example Managed WordPress hosting or VPS.',
				'rtcamp-amc-copilot'
			),
		],
		[
			'hosting',
			'control_panel',
			__( 'Control panel', 'rtcamp-amc-copilot' ),
			__(
				'For example cPanel, Plesk or the host’s own dashboard.',
				'rtcamp-amc-copilot'
			),
		],
		[
			'hosting',
			'account_owners',
			__( 'Hosting account owners', 'rtcamp-amc-copilot' ),
		],
		[
			'hosting',
			'hosting_users',
			__( 'Hosting users', 'rtcamp-amc-copilot' ),
		],
		[
			'hosting',
			'server_backup_frequency',
			__( 'Server backup frequency', 'rtcamp-amc-copilot' ),
		],
		[
			'hosting',
			'server_backup_retention',
			__( 'Server backup retention', 'rtcamp-amc-copilot' ),
		],
		[ 'hosting', 'redundancy', __( 'Redundancy', 'rtcamp-amc-copilot' ) ],
		[
			'database_backups',
			'backup_method',
			__( 'Backup method', 'rtcamp-amc-copilot' ),
			__(
				'For example Host snapshots and an offsite copy.',
				'rtcamp-amc-copilot'
			),
		],
		[
			'database_backups',
			'backup_schedule',
			__( 'Backup schedule', 'rtcamp-amc-copilot' ),
		],
		[
			'database_backups',
			'backup_retention',
			__( 'Backup retention', 'rtcamp-amc-copilot' ),
		],
	],
	domains: [
		[
			'domains',
			'domain_expiry',
			__( 'Domain expiry', 'rtcamp-amc-copilot' ),
			__( 'For example 2027–03–14.', 'rtcamp-amc-copilot' ),
		],
		[
			'domains',
			'auto_renewal',
			__( 'Domain auto-renewal', 'rtcamp-amc-copilot' ),
		],
		[ 'domains', 'subdomains', __( 'Subdomains', 'rtcamp-amc-copilot' ) ],
		[
			'ssl',
			'ssl_type',
			__( 'SSL certificate: purchased or free', 'rtcamp-amc-copilot' ),
		],
		[
			'ssl',
			'ssl_auto_renewal',
			__( 'SSL auto-renewal', 'rtcamp-amc-copilot' ),
		],
		[
			'accessibility',
			'defined_standard',
			__( 'Accessibility standard', 'rtcamp-amc-copilot' ),
			__( 'For example WCAG 2.2 AA.', 'rtcamp-amc-copilot' ),
		],
	],
} );

const environmentOptions = ( detected ) => [
	{
		value: '',
		label: sprintf(
			/* translators: %s: environment detected by WordPress, e.g. production. */
			__( 'Detect automatically (now: %s)', 'rtcamp-amc-copilot' ),
			detected
		),
	},
	{ value: 'production', label: __( 'Production', 'rtcamp-amc-copilot' ) },
	{ value: 'staging', label: __( 'Staging', 'rtcamp-amc-copilot' ) },
	{ value: 'development', label: __( 'Development', 'rtcamp-amc-copilot' ) },
	{ value: 'local', label: __( 'Local', 'rtcamp-amc-copilot' ) },
];

const siteTypeOptions = () => [
	{
		value: 'content',
		label: __(
			'Content site (smoke checks open the latest post)',
			'rtcamp-amc-copilot'
		),
	},
	{
		value: 'ecommerce',
		label: __(
			'Online shop (smoke checks open the latest product)',
			'rtcamp-amc-copilot'
		),
	},
];

/**
 * Initial form values: stored settings and saved fields, with detected
 * suggestions for every saved field that is still empty.
 *
 * @param {Object} setup    GET /setup.
 * @param {Object} settings GET /settings.
 * @param {Object} saved    GET /saved-fields.
 * @return {{form: Object, fields: Object, suggested: string[]}} Initial values.
 */
export const initialValues = ( setup, settings, saved ) => {
	const suggestions = setup.detected?.suggestions ?? {};
	const fields = {};
	const suggested = [];
	Object.entries( getStepFields() ).forEach( ( [ , list ] ) =>
		list.forEach( ( [ section, key ] ) => {
			const stored = saved.fields?.[ section ]?.[ key ];
			const suggestion = suggestions[ section ]?.[ key ];
			let value = stored ?? '';
			if ( ! value && suggestion ) {
				value = suggestion;
				suggested.push( `${ section }.${ key }` );
			}
			fields[ section ] = { ...fields[ section ], [ key ]: value };
		} )
	);
	return {
		form: {
			// GET /settings returns the effective environment; show "detect
			// automatically" unless it differs from what WordPress detects.
			environment:
				settings.environment === setup.detected?.environment
					? ''
					: ( settings.environment ?? '' ),
			site_type: settings.site_type,
			recipients: ( settings.recipients ?? [] ).join( '\n' ),
			digest_time: settings.digest_time,
			contract_start: settings.contract_start ?? '',
			notify_client_on_publish: !! settings.notify_client_on_publish,
		},
		fields,
		suggested,
	};
};

/**
 * Parse the recipients textarea.
 *
 * @param {string} text One address per line (commas also accepted).
 * @return {{list: string[], invalid: string|undefined}} Addresses and the first invalid one.
 */
export const parseRecipients = ( text ) => {
	const list = text
		.split( /[\n,]/ )
		.map( ( line ) => line.trim() )
		.filter( Boolean );
	return { list, invalid: list.find( ( email ) => ! EMAIL.test( email ) ) };
};

/**
 * Validate a step. Returns field errors keyed by field name (empty = valid).
 *
 * @param {string} slug Step slug.
 * @param {Object} form Form values.
 * @return {Object<string, string>} Errors.
 */
export const validateStep = ( slug, form ) => {
	if ( 'notifications' === slug ) {
		const { invalid } = parseRecipients( form.recipients );
		if ( invalid ) {
			return {
				recipients: sprintf(
					/* translators: %s: email address. */
					__(
						'“%s” is not a valid email address.',
						'rtcamp-amc-copilot'
					),
					invalid
				),
			};
		}
		if ( ! /^([01]\d|2[0-3]):[0-5]\d$/.test( form.digest_time ) ) {
			return {
				digest_time: __(
					'Enter a time as HH:MM.',
					'rtcamp-amc-copilot'
				),
			};
		}
	}
	if (
		'reports' === slug &&
		form.contract_start &&
		! DATE.test( form.contract_start )
	) {
		return {
			contract_start: __(
				'Enter a date as YYYY-MM-DD, or leave it empty.',
				'rtcamp-amc-copilot'
			),
		};
	}
	return {};
};

/**
 * Help text with an error in front of it (bold, "Error:" prefix).
 *
 * @param {string|undefined} error  Error.
 * @param {string}           [help] Help.
 * @return {Element|string|undefined} Help content.
 */
const withError = ( error, help ) =>
	error ? (
		<>
			<strong>
				{ sprintf(
					/* translators: %s: error message. */
					__( 'Error: %s', 'rtcamp-amc-copilot' ),
					error
				) }
			</strong>
			{ help && (
				<>
					<br />
					{ help }
				</>
			) }
		</>
	) : (
		help
	);

/**
 * Step indicator: an ordered list with the current step marked.
 *
 * @param {Object} props
 * @param {Array}  props.steps   Steps.
 * @param {number} props.current Current step index.
 * @return {Element} Indicator.
 */
function StepIndicator( { steps, current } ) {
	return (
		<ol
			className="amc-setup-steps"
			aria-label={ __( 'Setup steps', 'rtcamp-amc-copilot' ) }
		>
			{ steps.map( ( step, index ) => {
				let state = 'is-upcoming';
				if ( index < current ) {
					state = 'is-complete';
				} else if ( index === current ) {
					state = 'is-current';
				}
				return (
					<li
						key={ step.slug }
						className={ `amc-setup-step ${ state }` }
						aria-current={ index === current ? 'step' : undefined }
					>
						<span
							className="amc-setup-step-number"
							aria-hidden="true"
						>
							{ index + 1 }
						</span>
						<span className="amc-setup-step-label">
							{ step.label }
						</span>
						{ index < current && (
							<span className="screen-reader-text">
								{ __( '(done)', 'rtcamp-amc-copilot' ) }
							</span>
						) }
					</li>
				);
			} ) }
		</ol>
	);
}

/**
 * Text inputs for a list of saved fields.
 *
 * @param {Object}                          props
 * @param {Array}                           props.list      `[ section, field, label, help ]` rows.
 * @param {Object}                          props.fields    Values.
 * @param {string[]}                        props.suggested Keys filled from detection.
 * @param {(...args: unknown[]) => unknown} props.onChange  `( section, key, value )`.
 * @return {Element} Inputs.
 */
function SavedFieldInputs( { list, fields, suggested, onChange } ) {
	return list.map( ( [ section, key, label, help ] ) => {
		const isSuggested = suggested.includes( `${ section }.${ key }` );
		let description = help;
		if ( isSuggested ) {
			description = help
				? sprintf(
						/* translators: %s: the field's normal help text. */
						__(
							'Detected by AMC Copilot. %s',
							'rtcamp-amc-copilot'
						),
						help
					)
				: __( 'Detected by AMC Copilot.', 'rtcamp-amc-copilot' );
		}
		return (
			<TextControl
				key={ `${ section }.${ key }` }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ label }
				help={ description }
				value={ fields[ section ]?.[ key ] ?? '' }
				onChange={ ( value ) => onChange( section, key, value ) }
			/>
		);
	} );
}

/**
 * First-run setup wizard (`?page=amc-copilot&screen=setup`).
 *
 * Seven steps with Back / Skip / Next. Each step saves through the existing
 * routes (`/settings`, `/saved-fields`) when the engineer moves on; Skip
 * moves on without saving. Finish optionally queues every check once
 * (`/checks/run`) and marks setup complete (`/setup/complete`). It can be run
 * again any time from Settings.
 *
 * @return {Element} Screen.
 */
export default function Setup() {
	const fetcher = useCallback(
		() => Promise.all( [ getSetup(), getSettings(), getSavedFields() ] ),
		[]
	);
	const { data, error, isLoading, reload } = useResource( fetcher );
	const steps = getSteps();
	const stepFields = getStepFields();
	const [ step, setStep ] = useState( 0 );
	const [ values, setValues ] = useState( null );
	const [ errors, setErrors ] = useState( {} );
	const [ problem, setProblem ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ runChecks, setRunChecks ] = useState( true );
	const [ done, setDone ] = useState( null );
	const headingRef = useRef( null );
	const firstRender = useRef( true );

	useEffect( () => {
		if ( data && ! values ) {
			setValues( initialValues( ...data ) );
		}
	}, [ data, values ] );

	// Move focus to the new step's heading and announce it.
	useEffect( () => {
		if ( firstRender.current ) {
			firstRender.current = false;
			return;
		}
		headingRef.current?.focus();
		speak(
			sprintf(
				/* translators: 1: step number, 2: number of steps, 3: step name. */
				__( 'Step %1$d of %2$d: %3$s', 'rtcamp-amc-copilot' ),
				step + 1,
				steps.length,
				steps[ step ].label
			),
			'polite'
		);
		// eslint-disable-next-line react-hooks/exhaustive-deps -- Announce on step change only.
	}, [ step, done ] );

	const detected = data?.[ 0 ]?.detected ?? {};
	const current = steps[ step ];
	const isLast = step === steps.length - 1;

	const setForm = ( key ) => ( value ) =>
		setValues( ( prev ) => ( {
			...prev,
			form: { ...prev.form, [ key ]: value },
		} ) );

	const setField = ( section, key, value ) =>
		setValues( ( prev ) => ( {
			...prev,
			fields: {
				...prev.fields,
				[ section ]: { ...prev.fields[ section ], [ key ]: value },
			},
			suggested: prev.suggested.filter(
				( id ) => id !== `${ section }.${ key }`
			),
		} ) );

	/**
	 * Save the current step through the existing routes.
	 */
	const saveStep = async () => {
		const { form, fields } = values;
		const slug = current.slug;
		if ( 'environment' === slug ) {
			await saveSettings( {
				environment: form.environment,
				site_type: form.site_type,
			} );
		} else if ( stepFields[ slug ] ) {
			const changes = {};
			stepFields[ slug ].forEach( ( [ section, key ] ) => {
				changes[ section ] = {
					...changes[ section ],
					[ key ]: ( fields[ section ]?.[ key ] ?? '' ).trim(),
				};
			} );
			await saveSavedFields( changes );
		} else if ( 'notifications' === slug ) {
			await saveSettings( {
				recipients: parseRecipients( form.recipients ).list,
				digest_time: form.digest_time,
			} );
		} else if ( 'reports' === slug ) {
			await saveSettings( {
				contract_start: form.contract_start,
				notify_client_on_publish: form.notify_client_on_publish,
			} );
		}
	};

	const goTo = ( index ) => {
		setErrors( {} );
		setProblem( null );
		setStep( index );
	};

	const next = async () => {
		const found = validateStep( current.slug, values.form );
		setErrors( found );
		if ( Object.keys( found ).length ) {
			return;
		}
		setSaving( true );
		setProblem( null );
		try {
			await saveStep();
			goTo( step + 1 );
		} catch ( err ) {
			setProblem( err );
		} finally {
			setSaving( false );
		}
	};

	const finish = async () => {
		setSaving( true );
		setProblem( null );
		try {
			let queued = 0;
			if ( runChecks ) {
				queued = ( await runCheck( 'all' ) )?.runs?.length ?? 0;
			}
			await completeSetup();
			// The PHP "not set up yet" notice no longer applies.
			document
				.querySelectorAll( '.amc-setup-notice' )
				.forEach( ( node ) => node.remove() );
			setDone( { queued } );
		} catch ( err ) {
			setProblem( err );
		} finally {
			setSaving( false );
		}
	};

	const leave = ( screen ) => ( event ) => {
		if ( event.metaKey || event.ctrlKey || event.shiftKey ) {
			return;
		}
		event.preventDefault();
		navigate( screen );
	};

	const renderStep = () => {
		const { form, fields, suggested } = values;
		switch ( current.slug ) {
			case 'environment':
				return (
					<>
						<RadioControl
							label={ __(
								'Which environment is this?',
								'rtcamp-amc-copilot'
							) }
							help={ __(
								'Shown on reports. Each environment is checked and reported on its own.',
								'rtcamp-amc-copilot'
							) }
							selected={ form.environment }
							options={ environmentOptions(
								detected.environment
							) }
							onChange={ setForm( 'environment' ) }
						/>
						<RadioControl
							label={ __(
								'What kind of site is it?',
								'rtcamp-amc-copilot'
							) }
							selected={ form.site_type }
							options={ siteTypeOptions() }
							onChange={ setForm( 'site_type' ) }
						/>
					</>
				);
			case 'site':
			case 'domains':
			case 'hosting':
				return (
					<>
						{ 'hosting' === current.slug && detected.host && (
							<p className="description">
								{ sprintf(
									/* translators: 1: host, 2: web server, 3: PHP version. */
									__(
										'Detected: host %1$s, web server %2$s, PHP %3$s.',
										'rtcamp-amc-copilot'
									),
									detected.host.label ??
										__(
											'not recognised',
											'rtcamp-amc-copilot'
										),
									detected.host.web_server ??
										__( 'unknown', 'rtcamp-amc-copilot' ),
									detected.host.php_version ??
										__( 'unknown', 'rtcamp-amc-copilot' )
								) }
							</p>
						) }
						{ 'domains' === current.slug && (
							<p className="description">
								{ detected.ssl?.issuer
									? sprintf(
											/* translators: 1: domain, 2: certificate issuer, 3: expiry date. */
											__(
												'Detected: %1$s, certificate from %2$s, valid until %3$s.',
												'rtcamp-amc-copilot'
											),
											detected.primary_domain,
											detected.ssl.issuer,
											formatDate( detected.ssl.valid_to )
										)
									: sprintf(
											/* translators: %s: domain. */
											__(
												'Detected: %s. The SSL check has not read the certificate yet.',
												'rtcamp-amc-copilot'
											),
											detected.primary_domain
										) }
							</p>
						) }
						<SavedFieldInputs
							list={ stepFields[ current.slug ] }
							fields={ fields }
							suggested={ suggested }
							onChange={ setField }
						/>
					</>
				);
			case 'notifications':
				return (
					<>
						<TextareaControl
							__nextHasNoMarginBottom
							label={ __(
								'Engineer recipient emails',
								'rtcamp-amc-copilot'
							) }
							help={ withError(
								errors.recipients,
								detected.engineer_emails?.length
									? sprintf(
											/* translators: %s: comma-separated email addresses. */
											__(
												'One address per line. Leave empty to email every engineer: %s.',
												'rtcamp-amc-copilot'
											),
											detected.engineer_emails.join(
												', '
											)
										)
									: __(
											'One address per line. Leave empty to email every engineer.',
											'rtcamp-amc-copilot'
										)
							) }
							value={ form.recipients }
							rows={ 4 }
							aria-invalid={ !! errors.recipients }
							onChange={ setForm( 'recipients' ) }
						/>
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							type="time"
							label={ __(
								'Daily digest time',
								'rtcamp-amc-copilot'
							) }
							help={ withError(
								errors.digest_time,
								__(
									'In site time. Urgent alerts are sent straight away.',
									'rtcamp-amc-copilot'
								)
							) }
							value={ form.digest_time }
							aria-invalid={ !! errors.digest_time }
							onChange={ setForm( 'digest_time' ) }
						/>
					</>
				);
			case 'reports':
				return (
					<>
						<ul className="amc-setup-schedule">
							<li>
								{ __(
									'Monthly: on the 1st of each month at 06:00, for the previous month.',
									'rtcamp-amc-copilot'
								) }
							</li>
							<li>
								{ __(
									'Quarterly: on the first day of each contract quarter at 06:15.',
									'rtcamp-amc-copilot'
								) }
							</li>
							<li>
								{ __(
									'Annual: on the contract anniversary at 06:30.',
									'rtcamp-amc-copilot'
								) }
							</li>
						</ul>
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							type="date"
							label={ __(
								'Contract start date',
								'rtcamp-amc-copilot'
							) }
							help={ withError(
								errors.contract_start,
								__(
									'Quarters and years follow this date. Leave it empty to follow the calendar.',
									'rtcamp-amc-copilot'
								)
							) }
							value={ form.contract_start }
							aria-invalid={ !! errors.contract_start }
							onChange={ setForm( 'contract_start' ) }
						/>
						<CheckboxControl
							__nextHasNoMarginBottom
							label={ __(
								'Email client administrators when a report is published',
								'rtcamp-amc-copilot'
							) }
							checked={ form.notify_client_on_publish }
							onChange={ setForm( 'notify_client_on_publish' ) }
						/>
					</>
				);
			default:
				return (
					<Review
						steps={ steps }
						values={ values }
						detected={ detected }
						onEdit={ goTo }
						runChecks={ runChecks }
						onRunChecks={ setRunChecks }
					/>
				);
		}
	};

	if ( done ) {
		return (
			<ScreenState
				title={ __( 'Set up AMC Copilot', 'rtcamp-amc-copilot' ) }
				isLoading={ false }
				hasData
			>
				<Card className="amc-setup">
					<CardBody>
						<h3 ref={ headingRef } tabIndex={ -1 }>
							{ __( 'Setup complete', 'rtcamp-amc-copilot' ) }
						</h3>
						<Notice status="success" isDismissible={ false }>
							{ done.queued
								? sprintf(
										/* translators: %d: number of checks. */
										__(
											'AMC Copilot is set up. %d checks are queued and will finish in the background.',
											'rtcamp-amc-copilot'
										),
										done.queued
									)
								: __(
										'AMC Copilot is set up.',
										'rtcamp-amc-copilot'
									) }
						</Notice>
					</CardBody>
					<CardFooter className="amc-setup-footer">
						<Button
							variant="primary"
							href={ screenUrl( 'overview' ) }
							onClick={ leave( 'overview' ) }
						>
							{ __( 'Go to the Overview', 'rtcamp-amc-copilot' ) }
						</Button>
					</CardFooter>
				</Card>
			</ScreenState>
		);
	}

	return (
		<ScreenState
			title={ __( 'Set up AMC Copilot', 'rtcamp-amc-copilot' ) }
			description={ __(
				'A few questions so checks, alerts and reports start with the right details.',
				'rtcamp-amc-copilot'
			) }
			actions={
				<Button
					variant="tertiary"
					href={ screenUrl( 'overview' ) }
					onClick={ leave( 'overview' ) }
				>
					{ __( 'Exit setup', 'rtcamp-amc-copilot' ) }
				</Button>
			}
			isLoading={ isLoading }
			error={ error }
			onRetry={ reload }
			hasData={ !! values }
		>
			{ values && (
				<div className="amc-setup">
					<StepIndicator steps={ steps } current={ step } />
					<Card>
						<CardHeader>
							<h3
								ref={ headingRef }
								tabIndex={ -1 }
								className="amc-setup-title"
							>
								{ sprintf(
									/* translators: 1: step number, 2: number of steps, 3: step name. */
									__(
										'Step %1$d of %2$d: %3$s',
										'rtcamp-amc-copilot'
									),
									step + 1,
									steps.length,
									current.label
								) }
							</h3>
						</CardHeader>
						<CardBody className="amc-setup-body">
							<p>{ current.description }</p>
							{ problem && <ErrorNotice error={ problem } /> }
							{ renderStep() }
						</CardBody>
						<CardFooter className="amc-setup-footer">
							<Button
								variant="secondary"
								onClick={ () => goTo( step - 1 ) }
								disabled={ 0 === step || saving }
								accessibleWhenDisabled
							>
								{ __( 'Back', 'rtcamp-amc-copilot' ) }
							</Button>
							<div className="amc-setup-footer-end">
								{ ! isLast && (
									<Button
										variant="tertiary"
										onClick={ () => goTo( step + 1 ) }
										disabled={ saving }
										accessibleWhenDisabled
									>
										{ __( 'Skip', 'rtcamp-amc-copilot' ) }
									</Button>
								) }
								<Button
									variant="primary"
									onClick={ isLast ? finish : next }
									isBusy={ saving }
									disabled={ saving }
									accessibleWhenDisabled
								>
									{ isLast
										? __(
												'Finish setup',
												'rtcamp-amc-copilot'
											)
										: __( 'Next', 'rtcamp-amc-copilot' ) }
								</Button>
							</div>
						</CardFooter>
					</Card>
				</div>
			) }
		</ScreenState>
	);
}

/**
 * Review step: every answer, grouped by step, with a link back to each.
 *
 * @param {Object}                          props
 * @param {Array}                           props.steps       Steps.
 * @param {Object}                          props.values      Form values.
 * @param {Object}                          props.detected    Detected values.
 * @param {(...args: unknown[]) => unknown} props.onEdit      Go to a step index.
 * @param {boolean}                         props.runChecks   Whether Finish queues every check.
 * @param {(...args: unknown[]) => unknown} props.onRunChecks Toggle it.
 * @return {Element} Review.
 */
function Review( { steps, values, detected, onEdit, runChecks, onRunChecks } ) {
	const { form, fields } = values;
	const stepFields = getStepFields();
	const notSet = __( 'Not set', 'rtcamp-amc-copilot' );
	const environment = environmentOptions( detected.environment ).find(
		( option ) => option.value === form.environment
	);
	const siteType = siteTypeOptions().find(
		( option ) => option.value === form.site_type
	);
	const recipients = parseRecipients( form.recipients ).list;

	const rows = {
		environment: [
			[ __( 'Environment', 'rtcamp-amc-copilot' ), environment?.label ],
			[ __( 'Site type', 'rtcamp-amc-copilot' ), siteType?.label ],
		],
		notifications: [
			[
				__( 'Recipients', 'rtcamp-amc-copilot' ),
				recipients.length
					? recipients.join( ', ' )
					: __( 'Every engineer', 'rtcamp-amc-copilot' ),
			],
			[
				__( 'Daily digest time', 'rtcamp-amc-copilot' ),
				form.digest_time,
			],
		],
		reports: [
			[
				__( 'Contract start date', 'rtcamp-amc-copilot' ),
				form.contract_start ||
					__( 'Calendar year', 'rtcamp-amc-copilot' ),
			],
			[
				__( 'Client emails on publish', 'rtcamp-amc-copilot' ),
				form.notify_client_on_publish
					? __( 'Yes', 'rtcamp-amc-copilot' )
					: __( 'No', 'rtcamp-amc-copilot' ),
			],
		],
	};
	Object.entries( stepFields ).forEach( ( [ slug, list ] ) => {
		rows[ slug ] = list.map( ( [ section, key, label ] ) => [
			label,
			fields[ section ]?.[ key ],
		] );
	} );

	return (
		<>
			{ steps.slice( 0, -1 ).map( ( item, index ) => (
				<div key={ item.slug } className="amc-setup-review">
					<div className="amc-setup-review-head">
						<h4>{ item.label }</h4>
						<Button
							variant="link"
							onClick={ () => onEdit( index ) }
							aria-label={ sprintf(
								/* translators: %s: step name. */
								__( 'Edit %s', 'rtcamp-amc-copilot' ),
								item.label
							) }
						>
							{ __( 'Edit', 'rtcamp-amc-copilot' ) }
						</Button>
					</div>
					<table className="widefat striped">
						<tbody>
							{ rows[ item.slug ].map( ( [ label, value ] ) => (
								<tr key={ label }>
									<th scope="row">{ label }</th>
									<td>
										{ value ? value : <em>{ notSet }</em> }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) ) }
			<CheckboxControl
				__nextHasNoMarginBottom
				label={ __( 'Run every check once now', 'rtcamp-amc-copilot' ) }
				help={ __(
					'The checks are queued and run in the background, so the Overview fills in within a few minutes.',
					'rtcamp-amc-copilot'
				) }
				checked={ runChecks }
				onChange={ onRunChecks }
			/>
		</>
	);
}
