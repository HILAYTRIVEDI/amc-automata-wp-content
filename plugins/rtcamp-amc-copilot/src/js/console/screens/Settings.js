/**
 * WordPress dependencies
 */
import {
	Button,
	Notice,
	Panel,
	PanelBody,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { trash } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import {
	getCandidateUsers,
	getChecks,
	getEngineers,
	getSavedFields,
	getSettings,
	grantAccess,
	revokeAccess,
	saveSavedFields,
	saveSettings,
} from '../api';
import DataTable from '../components/DataTable';
import ScreenState, { ErrorNotice } from '../components/ScreenState';
import { getIntervals } from '../constants';
import useNotice from '../hooks/useNotice';
import useResource from '../hooks/useResource';
import { customField, enumField, textField, toElements } from '../utils/fields';
import { intervalLabel } from '../utils/format';

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

const environmentOptions = () => [
	{ value: '', label: __( 'Detect automatically', 'rtcamp-amc-copilot' ) },
	{ value: 'production', label: __( 'Production', 'rtcamp-amc-copilot' ) },
	{ value: 'staging', label: __( 'Staging', 'rtcamp-amc-copilot' ) },
	{ value: 'development', label: __( 'Development', 'rtcamp-amc-copilot' ) },
	{ value: 'local', label: __( 'Local', 'rtcamp-amc-copilot' ) },
];

/**
 * Engineers with console access: list, grant and revoke
 * (`/access`; needs `amc_manage` and `manage_options`).
 *
 * @return {Element} Panel body content.
 */
function Engineers() {
	const { data, error, reload } = useResource( getEngineers );
	const users = useResource( getCandidateUsers );
	const notice = useNotice();
	const [ userId, setUserId ] = useState( '' );

	const act = async ( promise, message ) => {
		try {
			await promise;
			notice.success( message );
			setUserId( '' );
			reload();
			users.reload();
		} catch ( err ) {
			notice.error( err.message );
		}
	};

	const fields = useMemo(
		() => [
			textField( 'display_name', __( 'Name', 'rtcamp-amc-copilot' ) ),
			textField( 'login', __( 'Username', 'rtcamp-amc-copilot' ) ),
			textField( 'email', __( 'Email', 'rtcamp-amc-copilot' ) ),
		],
		[]
	);

	const actions = useMemo(
		() => [
			{
				id: 'revoke',
				label: __( 'Revoke access', 'rtcamp-amc-copilot' ),
				icon: trash,
				modalHeader: __( 'Revoke access', 'rtcamp-amc-copilot' ),
				modalSize: 'small',
				RenderModal: ( { items, closeModal } ) => (
					<div>
						<p>
							{ sprintf(
								/* translators: %s: user name. */
								__(
									'Remove console access for %s? They keep their normal site role.',
									'rtcamp-amc-copilot'
								),
								items[ 0 ].display_name
							) }
						</p>
						<div className="amc-modal-actions">
							<Button variant="tertiary" onClick={ closeModal }>
								{ __( 'Cancel', 'rtcamp-amc-copilot' ) }
							</Button>
							<Button
								variant="primary"
								isDestructive
								onClick={ async () => {
									await act(
										revokeAccess( items[ 0 ].id ),
										__(
											'Access revoked.',
											'rtcamp-amc-copilot'
										)
									);
									closeModal();
								} }
							>
								{ __( 'Revoke access', 'rtcamp-amc-copilot' ) }
							</Button>
						</div>
					</div>
				),
			},
		],
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[]
	);

	if ( error ) {
		return <ErrorNotice error={ error } />;
	}

	return (
		<>
			<p className="description">
				{ __(
					'Grants the amc_manage capability to the individual account. Changes need manage_options and are logged.',
					'rtcamp-amc-copilot'
				) }
			</p>
			{ notice.node }
			{ data && (
				<DataTable
					data={ data }
					fields={ fields }
					actions={ actions }
					exportName="amc-settings-engineers"
				/>
			) }
			<div className="amc-filters">
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Give access to', 'rtcamp-amc-copilot' ) }
					value={ userId }
					options={ [
						{
							value: '',
							label: __( 'Choose a user…', 'rtcamp-amc-copilot' ),
						},
						...( users.data ?? [] ).map( ( user ) => ( {
							value: String( user.id ),
							label: `${ user.name } (${ user.slug })`,
						} ) ),
					] }
					onChange={ setUserId }
				/>
				<Button
					variant="secondary"
					disabled={ ! userId }
					accessibleWhenDisabled
					onClick={ () =>
						act(
							grantAccess( Number( userId ) ),
							__( 'Access granted.', 'rtcamp-amc-copilot' )
						)
					}
				>
					{ __( 'Grant access', 'rtcamp-amc-copilot' ) }
				</Button>
			</div>
		</>
	);
}

/**
 * Saved report fields: carried-forward values (`/saved-fields`). Saving is
 * lane D1 and shows "not available yet" until it ships.
 *
 * @return {Element} Panel body content.
 */
function SavedFieldsEditor() {
	const { data, error } = useResource( getSavedFields );
	const [ values, setValues ] = useState( null );
	const [ problem, setProblem ] = useState( null );
	const notice = useNotice();

	useEffect( () => {
		if ( data ) {
			setValues( data.fields );
		}
	}, [ data ] );

	const rows = useMemo(
		() =>
			data && values
				? Object.entries( data.schema ).flatMap(
						( [ section, keys ] ) =>
							keys.map( ( key ) => ( {
								id: `${ section }.${ key }`,
								section,
								key,
								label: key.replace( /_/g, ' ' ),
								value: values[ section ]?.[ key ] ?? '',
							} ) )
					)
				: [],
		[ data, values ]
	);

	const fields = useMemo(
		() => [
			textField( 'section', __( 'Section', 'rtcamp-amc-copilot' ) ),
			textField( 'label', __( 'Field', 'rtcamp-amc-copilot' ) ),
			customField(
				'value',
				__( 'Value', 'rtcamp-amc-copilot' ),
				( item ) => item.value,
				( item ) => (
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						hideLabelFromVision
						label={ `${ item.section }: ${ item.label }` }
						value={ item.value }
						onChange={ ( value ) =>
							setValues( ( current ) => ( {
								...current,
								[ item.section ]: {
									...current[ item.section ],
									[ item.key ]: value,
								},
							} ) )
						}
					/>
				)
			),
		],
		[]
	);

	const save = async () => {
		setProblem( null );
		try {
			await saveSavedFields( values );
			notice.success(
				__( 'Saved fields updated.', 'rtcamp-amc-copilot' )
			);
		} catch ( err ) {
			setProblem( err );
		}
	};

	if ( error ) {
		return <ErrorNotice error={ error } />;
	}

	return (
		<>
			{ notice.node }
			{ problem && <ErrorNotice error={ problem } /> }
			{ data && (
				<DataTable
					data={ rows }
					fields={ fields }
					exportName="amc-settings-saved-fields"
					initialView={ { perPage: 20 } }
				/>
			) }
			<Button
				variant="secondary"
				onClick={ save }
				disabled={ ! values }
				accessibleWhenDisabled
			>
				{ __( 'Save saved fields', 'rtcamp-amc-copilot' ) }
			</Button>
		</>
	);
}

/**
 * Settings screen (`GET`/`POST /settings`): site, notifications, smoke
 * credentials, the check schedule, engineers with access, saved report
 * fields, disclaimers, retention and the kill switch.
 *
 * @return {Element} Screen.
 */
export default function Settings() {
	const { data, error, isLoading, reload } = useResource( getSettings );
	const checksResource = useResource( getChecks );
	const notice = useNotice();
	const [ form, setForm ] = useState( null );
	const [ recipientsText, setRecipientsText ] = useState( '' );
	const [ password, setPassword ] = useState( '' );
	const [ overrides, setOverrides ] = useState( {} );
	const [ saving, setSaving ] = useState( false );

	useEffect( () => {
		if ( data ) {
			setForm( data );
			setRecipientsText( data.recipients.join( '\n' ) );
			setOverrides( data.checks );
		}
	}, [ data ] );

	const set = ( key ) => ( value ) =>
		setForm( ( current ) => ( { ...current, [ key ]: value } ) );
	const setNested = ( key, nested ) => ( value ) =>
		setForm( ( current ) => ( {
			...current,
			[ key ]: { ...current[ key ], [ nested ]: value },
		} ) );
	const patchCheck = useCallback(
		( id, patch ) =>
			setOverrides( ( current ) => ( {
				...current,
				[ id ]: {
					enabled: true,
					interval: '',
					...current[ id ],
					...patch,
				},
			} ) ),
		[]
	);

	const checkRows = useMemo(
		() =>
			( checksResource.data ?? [] ).map( ( check ) => ( {
				id: check.id,
				label: check.label,
				group_label: check.group_label,
				default_schedule: check.default_schedule,
				enabled: overrides[ check.id ]?.enabled ?? true,
				interval: overrides[ check.id ]?.interval ?? '',
			} ) ),
		[ checksResource.data, overrides ]
	);

	const checkFields = useMemo(
		() => [
			textField( 'label', __( 'Check', 'rtcamp-amc-copilot' ) ),
			textField( 'id', __( 'ID', 'rtcamp-amc-copilot' ) ),
			enumField(
				'group_label',
				__( 'Group', 'rtcamp-amc-copilot' ),
				toElements( [
					...new Set(
						( checksResource.data ?? [] ).map(
							( c ) => c.group_label
						)
					),
				] ),
				{ filterBy: { operators: [ 'isAny' ], isPrimary: true } }
			),
			customField(
				'enabled',
				__( 'Enabled', 'rtcamp-amc-copilot' ),
				( item ) => ( item.enabled ? 'on' : 'off' ),
				( item ) => (
					<ToggleControl
						__nextHasNoMarginBottom
						aria-label={ sprintf(
							/* translators: %s: check name. */
							__( 'Enable %s', 'rtcamp-amc-copilot' ),
							item.label
						) }
						label={
							item.enabled
								? __( 'On', 'rtcamp-amc-copilot' )
								: __( 'Off', 'rtcamp-amc-copilot' )
						}
						checked={ item.enabled }
						onChange={ ( enabled ) =>
							patchCheck( item.id, { enabled } )
						}
					/>
				),
				{
					elements: [
						{
							value: 'on',
							label: __( 'On', 'rtcamp-amc-copilot' ),
						},
						{
							value: 'off',
							label: __( 'Off', 'rtcamp-amc-copilot' ),
						},
					],
					filterBy: { operators: [ 'isAny' ] },
				}
			),
			customField(
				'interval',
				__( 'Interval', 'rtcamp-amc-copilot' ),
				( item ) =>
					intervalLabel( item.interval || item.default_schedule ),
				( item ) =>
					'event' === item.default_schedule ? (
						<span>{ intervalLabel( 'event' ) }</span>
					) : (
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							hideLabelFromVision
							label={ sprintf(
								/* translators: %s: check name. */
								__( 'Interval for %s', 'rtcamp-amc-copilot' ),
								item.label
							) }
							value={ item.interval }
							options={ [
								{
									value: '',
									label: sprintf(
										/* translators: %s: default interval, e.g. Daily. */
										__(
											'Default (%s)',
											'rtcamp-amc-copilot'
										),
										intervalLabel( item.default_schedule )
									),
								},
								...getIntervals(),
							] }
							onChange={ ( interval ) =>
								patchCheck( item.id, { interval } )
							}
						/>
					)
			),
		],
		[ checksResource.data, patchCheck ]
	);

	const save = async () => {
		const recipients = recipientsText
			.split( /[\n,;]+/ )
			.map( ( value ) => value.trim() )
			.filter( Boolean );
		const badAddress = recipients.find(
			( value ) => ! EMAIL.test( value )
		);
		if ( badAddress ) {
			notice.error(
				sprintf(
					/* translators: %s: email address. */
					__(
						'"%s" is not a valid email address.',
						'rtcamp-amc-copilot'
					),
					badAddress
				)
			);
			return;
		}
		if ( ! /^\d{2}:\d{2}$/.test( form.digest_time ) ) {
			notice.error(
				__( 'Enter the digest time as HH:MM.', 'rtcamp-amc-copilot' )
			);
			return;
		}

		// Overrides for checks this site does not have (a lane not shipped yet)
		// are kept; for known checks only non-default values are stored.
		const known = new Set(
			( checksResource.data ?? [] ).map( ( c ) => c.id )
		);
		const checks = Object.fromEntries(
			Object.entries( overrides ).filter(
				( [ id, value ] ) =>
					! known.has( id ) ||
					false === value.enabled ||
					'' !== ( value.interval ?? '' )
			)
		);

		setSaving( true );
		try {
			const saved = await saveSettings( {
				site_type: form.site_type,
				environment: form.environment,
				contract_start: form.contract_start,
				recipients,
				digest_time: form.digest_time,
				immediate_alerts_per_hour: Number(
					form.immediate_alerts_per_hour
				),
				notify_client_on_publish: form.notify_client_on_publish,
				loopback_auth: {
					username: form.loopback_auth.username,
					...( password ? { password } : {} ),
				},
				kill_switch: form.kill_switch,
				checks,
				retention_months: Number( form.retention_months ),
				disclaimers: form.disclaimers,
			} );
			setForm( saved );
			setOverrides( saved.checks );
			setRecipientsText( saved.recipients.join( '\n' ) );
			setPassword( '' );
			notice.success( __( 'Settings saved.', 'rtcamp-amc-copilot' ) );
		} catch ( err ) {
			notice.error( err.message );
		} finally {
			setSaving( false );
		}
	};

	return (
		<ScreenState
			title={ __( 'Settings', 'rtcamp-amc-copilot' ) }
			actions={
				<Button
					variant="primary"
					onClick={ save }
					isBusy={ saving }
					disabled={ ! form || saving }
					accessibleWhenDisabled
				>
					{ __( 'Save settings', 'rtcamp-amc-copilot' ) }
				</Button>
			}
			isLoading={ isLoading }
			error={ error }
			onRetry={ reload }
			hasData={ !! form }
		>
			{ notice.node }
			{ form && (
				<Panel>
					<PanelBody
						title={ __( 'Site', 'rtcamp-amc-copilot' ) }
						initialOpen
					>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Site type', 'rtcamp-amc-copilot' ) }
							help={ __(
								'Decides whether the latest post or the latest product is a default smoke URL.',
								'rtcamp-amc-copilot'
							) }
							value={ form.site_type }
							options={ [
								{
									value: 'content',
									label: __(
										'Content site (latest post)',
										'rtcamp-amc-copilot'
									),
								},
								{
									value: 'ecommerce',
									label: __(
										'Shop (latest product)',
										'rtcamp-amc-copilot'
									),
								},
							] }
							onChange={ set( 'site_type' ) }
						/>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __(
								'Environment label for reports',
								'rtcamp-amc-copilot'
							) }
							value={ form.environment }
							options={ environmentOptions() }
							onChange={ set( 'environment' ) }
						/>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							type="date"
							label={ __(
								'Contract start date',
								'rtcamp-amc-copilot'
							) }
							help={ __(
								'Quarterly and annual reports follow this date; the calendar is used when empty.',
								'rtcamp-amc-copilot'
							) }
							value={ form.contract_start }
							onChange={ set( 'contract_start' ) }
						/>
					</PanelBody>

					<PanelBody
						title={ __( 'Notifications', 'rtcamp-amc-copilot' ) }
						initialOpen={ false }
					>
						<TextareaControl
							__nextHasNoMarginBottom
							label={ __( 'Recipients', 'rtcamp-amc-copilot' ) }
							help={ __(
								'One email address per line. Leave empty to email every engineer.',
								'rtcamp-amc-copilot'
							) }
							rows={ 4 }
							value={ recipientsText }
							onChange={ setRecipientsText }
						/>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							type="time"
							label={ __(
								'Daily digest time (site time)',
								'rtcamp-amc-copilot'
							) }
							value={ form.digest_time }
							onChange={ set( 'digest_time' ) }
						/>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							type="number"
							min={ 0 }
							max={ 100 }
							label={ __(
								'Immediate alerts per hour',
								'rtcamp-amc-copilot'
							) }
							help={ __(
								'Alerts over this limit go into the next digest.',
								'rtcamp-amc-copilot'
							) }
							value={ form.immediate_alerts_per_hour }
							onChange={ set( 'immediate_alerts_per_hour' ) }
						/>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Email client administrators when a report is published',
								'rtcamp-amc-copilot'
							) }
							checked={ form.notify_client_on_publish }
							onChange={ set( 'notify_client_on_publish' ) }
						/>
					</PanelBody>

					<PanelBody
						title={ __(
							'Smoke check credentials',
							'rtcamp-amc-copilot'
						) }
						initialOpen={ false }
					>
						<p className="description">
							{ __(
								'Optional basic-auth credentials for sites behind HTTP authentication.',
								'rtcamp-amc-copilot'
							) }
						</p>
						{ ! form.loopback_auth.encryption_available && (
							<Notice status="warning" isDismissible={ false }>
								{ __(
									'A password cannot be stored until AMC_COPILOT_ENCRYPTION_KEY is defined in wp-config.php.',
									'rtcamp-amc-copilot'
								) }
							</Notice>
						) }
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Username', 'rtcamp-amc-copilot' ) }
							value={ form.loopback_auth.username }
							onChange={ setNested(
								'loopback_auth',
								'username'
							) }
							autoComplete="off"
						/>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							type="password"
							label={ __( 'Password', 'rtcamp-amc-copilot' ) }
							help={
								form.loopback_auth.has_password
									? __(
											'A password is stored. Leave empty to keep it.',
											'rtcamp-amc-copilot'
										)
									: __(
											'Write-only: it is stored encrypted and never shown.',
											'rtcamp-amc-copilot'
										)
							}
							value={ password }
							onChange={ setPassword }
							disabled={
								! form.loopback_auth.encryption_available
							}
							autoComplete="new-password"
						/>
					</PanelBody>

					<PanelBody
						title={ __( 'Check schedule', 'rtcamp-amc-copilot' ) }
						initialOpen={ false }
					>
						{ checksResource.error && (
							<ErrorNotice error={ checksResource.error } />
						) }
						{ checksResource.data && (
							<DataTable
								data={ checkRows }
								fields={ checkFields }
								exportName="amc-settings-checks"
								initialView={ { perPage: 10 } }
							/>
						) }
					</PanelBody>

					<PanelBody
						title={ __(
							'Engineers with access',
							'rtcamp-amc-copilot'
						) }
						initialOpen={ false }
					>
						<Engineers />
					</PanelBody>

					<PanelBody
						title={ __(
							'Saved report fields',
							'rtcamp-amc-copilot'
						) }
						initialOpen={ false }
					>
						<SavedFieldsEditor />
					</PanelBody>

					<PanelBody
						title={ __(
							'Report disclaimers',
							'rtcamp-amc-copilot'
						) }
						initialOpen={ false }
					>
						<p className="description">
							{ __(
								'Leave a field empty to use the report block’s built-in text.',
								'rtcamp-amc-copilot'
							) }
						</p>
						{ [
							[
								'backups',
								__( 'Backups', 'rtcamp-amc-copilot' ),
							],
							[
								'hosting',
								__( 'Hosting', 'rtcamp-amc-copilot' ),
							],
							[
								'domains',
								__( 'Domains', 'rtcamp-amc-copilot' ),
							],
							[
								'accessibility',
								__( 'Accessibility', 'rtcamp-amc-copilot' ),
							],
						].map( ( [ key, label ] ) => (
							<TextareaControl
								key={ key }
								__nextHasNoMarginBottom
								label={ label }
								rows={ 3 }
								value={ form.disclaimers[ key ] }
								onChange={ setNested( 'disclaimers', key ) }
							/>
						) ) }
					</PanelBody>

					<PanelBody
						title={ __(
							'Retention and kill switch',
							'rtcamp-amc-copilot'
						) }
						initialOpen={ false }
					>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							type="number"
							min={ 1 }
							max={ 60 }
							label={ __(
								'Keep check history for (months)',
								'rtcamp-amc-copilot'
							) }
							help={ __(
								'Sampled slow queries are always kept for 90 days.',
								'rtcamp-amc-copilot'
							) }
							value={ form.retention_months }
							onChange={ set( 'retention_months' ) }
						/>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Kill switch: stop all capture and cron jobs',
								'rtcamp-amc-copilot'
							) }
							help={
								form.kill_switch_locked
									? __(
											'AMC_COPILOT_DISABLED is defined in wp-config.php and always wins. It cannot be changed here.',
											'rtcamp-amc-copilot'
										)
									: __(
											'The AMC_COPILOT_DISABLED constant always overrides this setting.',
											'rtcamp-amc-copilot'
										)
							}
							checked={
								form.kill_switch_locked || form.kill_switch
							}
							disabled={ form.kill_switch_locked }
							onChange={ set( 'kill_switch' ) }
						/>
					</PanelBody>
				</Panel>
			) }
		</ScreenState>
	);
}
