/**
 * WordPress dependencies
 */
import { Button, Modal, TabPanel } from '@wordpress/components';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

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
import ScreenState, { ErrorNotice, Loading } from '../components/ScreenState';
import SimpleTable from '../components/SimpleTable';
import { getIntervals } from '../constants';
import useNotice from '../hooks/useNotice';
import useResource from '../hooks/useResource';
import { customField, textField } from '../utils/fields';
import { intervalLabel } from '../utils/format';

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/**
 * Help text for a field with a validation error: the error comes first and is
 * bold with an "Error:" prefix (so it is not conveyed by emphasis alone), then
 * the field's normal help. `TextControl` and `TextareaControl` link `help` to
 * the input with `aria-describedby`, so the error is read with the field.
 *
 * @param {string|undefined} error  Error message.
 * @param {string}           [help] Normal help text.
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

const environmentOptions = () => [
	{ value: '', label: __( 'Detect automatically', 'rtcamp-amc-copilot' ) },
	{ value: 'production', label: __( 'Production', 'rtcamp-amc-copilot' ) },
	{ value: 'staging', label: __( 'Staging', 'rtcamp-amc-copilot' ) },
	{ value: 'development', label: __( 'Development', 'rtcamp-amc-copilot' ) },
	{ value: 'local', label: __( 'Local', 'rtcamp-amc-copilot' ) },
];

/**
 * `aria-describedby` for a field whose help paragraph has the id
 * `<id>-description` (only when there is help or an error to point at).
 *
 * @param {string}           id      Field id.
 * @param {string|undefined} help    Help text.
 * @param {string|undefined} [error] Error message.
 * @return {string|undefined} Attribute value.
 */
const describedBy = ( id, help, error ) =>
	help || error ? `${ id }-description` : undefined;

/**
 * One row of a core settings table (`form-table`): label in the header cell,
 * the control and its description in the data cell.
 *
 * @param {Object}  props
 * @param {string}  props.id       Control id (the label points at it).
 * @param {string}  props.label    Label.
 * @param {string}  [props.help]   Description under the control.
 * @param {string}  [props.error]  Validation error, shown before the help.
 * @param {Element} props.children Control.
 * @return {Element} Row.
 */
function Row( { id, label, help, error, children } ) {
	return (
		<tr>
			<th scope="row">
				<label htmlFor={ id }>{ label }</label>
			</th>
			<td>
				{ children }
				{ ( help || error ) && (
					<p className="description" id={ `${ id }-description` }>
						{ withError( error, help ) }
					</p>
				) }
			</td>
		</tr>
	);
}

/**
 * A checkbox row as on core settings screens: the setting name in the header
 * cell, a fieldset with the checkbox and its label in the data cell.
 *
 * @param {Object}                          props
 * @param {string}                          props.id         Checkbox id.
 * @param {string}                          props.title      Setting name.
 * @param {string}                          props.label      Checkbox label.
 * @param {string}                          [props.help]     Description.
 * @param {boolean}                         props.checked    State.
 * @param {boolean}                         [props.disabled] Disabled.
 * @param {(...args: unknown[]) => unknown} props.onChange   Called with the new state.
 * @return {Element} Row.
 */
function CheckboxRow( {
	id,
	title,
	label,
	help,
	checked,
	disabled,
	onChange,
} ) {
	return (
		<tr>
			<th scope="row">{ title }</th>
			<td>
				<fieldset>
					<legend className="screen-reader-text">
						<span>{ title }</span>
					</legend>
					<label htmlFor={ id }>
						<input
							id={ id }
							type="checkbox"
							checked={ checked }
							disabled={ disabled }
							aria-describedby={ describedBy( id, help ) }
							onChange={ ( event ) =>
								onChange( event.target.checked )
							}
						/>{ ' ' }
						{ label }
					</label>
					{ help && (
						<p className="description" id={ `${ id }-description` }>
							{ help }
						</p>
					) }
				</fieldset>
			</td>
		</tr>
	);
}

/**
 * Engineers with console access: list, grant and revoke
 * (`/access`; needs `amc_manage` and `manage_options`).
 *
 * @return {Element} Tab content.
 */
function Engineers() {
	const { data, error, reload } = useResource( getEngineers );
	const users = useResource( getCandidateUsers );
	const notice = useNotice();
	const [ userId, setUserId ] = useState( '' );
	const [ revoking, setRevoking ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const act = async ( promise, message ) => {
		setBusy( true );
		try {
			await promise;
			notice.success( message );
			setUserId( '' );
			reload();
			users.reload();
		} catch ( err ) {
			notice.error( err.message );
		} finally {
			setBusy( false );
		}
	};

	const fields = useMemo(
		() => [
			textField( 'display_name', __( 'Name', 'rtcamp-amc-copilot' ), {
				render: ( { item } ) => <strong>{ item.display_name }</strong>,
			} ),
			textField( 'login', __( 'Username', 'rtcamp-amc-copilot' ) ),
			textField( 'email', __( 'Email', 'rtcamp-amc-copilot' ) ),
			customField(
				'revoke',
				__( 'Actions', 'rtcamp-amc-copilot' ),
				() => '',
				( item ) => (
					<Button
						variant="link"
						isDestructive
						onClick={ () => setRevoking( item ) }
						aria-label={ sprintf(
							/* translators: %s: user name. */
							__( 'Revoke access for %s', 'rtcamp-amc-copilot' ),
							item.display_name
						) }
					>
						{ __( 'Revoke access', 'rtcamp-amc-copilot' ) }
					</Button>
				)
			),
		],
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
			{ ! data && <Loading /> }
			{ data && (
				<SimpleTable
					data={ data }
					fields={ fields }
					columns={ [ 'display_name', 'login', 'email', 'revoke' ] }
					exportName="amc-settings-engineers"
					label={ __(
						'Engineers with access',
						'rtcamp-amc-copilot'
					) }
					emptyText={ __(
						'No engineers have access yet.',
						'rtcamp-amc-copilot'
					) }
				/>
			) }
			<table className="form-table" role="presentation">
				<tbody>
					<Row
						id="amc-setting-grant-user"
						label={ __( 'Give access to', 'rtcamp-amc-copilot' ) }
					>
						<select
							id="amc-setting-grant-user"
							value={ userId }
							onChange={ ( event ) =>
								setUserId( event.target.value )
							}
						>
							<option value="">
								{ __( 'Choose a user…', 'rtcamp-amc-copilot' ) }
							</option>
							{ ( users.data ?? [] ).map( ( user ) => (
								<option
									key={ user.id }
									value={ String( user.id ) }
								>
									{ `${ user.name } (${ user.slug })` }
								</option>
							) ) }
						</select>{ ' ' }
						<Button
							variant="secondary"
							disabled={ ! userId || busy }
							accessibleWhenDisabled
							onClick={ () =>
								act(
									grantAccess( Number( userId ) ),
									__(
										'Access granted.',
										'rtcamp-amc-copilot'
									)
								)
							}
						>
							{ __( 'Grant access', 'rtcamp-amc-copilot' ) }
						</Button>
					</Row>
				</tbody>
			</table>
			{ revoking && (
				<Modal
					title={ __( 'Revoke access', 'rtcamp-amc-copilot' ) }
					size="small"
					onRequestClose={ () => setRevoking( null ) }
				>
					<p>
						{ sprintf(
							/* translators: %s: user name. */
							__(
								'Remove console access for %s? They keep their normal site role.',
								'rtcamp-amc-copilot'
							),
							revoking.display_name
						) }
					</p>
					<div className="amc-modal-actions">
						<Button
							variant="tertiary"
							onClick={ () => setRevoking( null ) }
						>
							{ __( 'Cancel', 'rtcamp-amc-copilot' ) }
						</Button>
						<Button
							variant="primary"
							isDestructive
							isBusy={ busy }
							disabled={ busy }
							accessibleWhenDisabled
							onClick={ async () => {
								await act(
									revokeAccess( revoking.id ),
									__(
										'Access revoked.',
										'rtcamp-amc-copilot'
									)
								);
								setRevoking( null );
							} }
						>
							{ __( 'Revoke access', 'rtcamp-amc-copilot' ) }
						</Button>
					</div>
				</Modal>
			) }
		</>
	);
}

/**
 * Saved report fields: carried-forward values (`/saved-fields`). Saving is
 * lane D1 and shows "not available yet" until it ships.
 *
 * @return {Element} Tab content.
 */
function SavedFieldsEditor() {
	const { data, error } = useResource( getSavedFields );
	const [ values, setValues ] = useState( null );
	const [ problem, setProblem ] = useState( null );
	const [ saving, setSaving ] = useState( false );
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
								section: section.replace( /_/g, ' ' ),
								sectionKey: section,
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
					<input
						type="text"
						className="regular-text"
						aria-label={ `${ item.section }: ${ item.label }` }
						value={ item.value }
						onChange={ ( event ) => {
							const { value } = event.target;
							setValues( ( current ) => ( {
								...current,
								[ item.sectionKey ]: {
									...current[ item.sectionKey ],
									[ item.key ]: value,
								},
							} ) );
						} }
					/>
				)
			),
		],
		[]
	);

	const save = async () => {
		setProblem( null );
		setSaving( true );
		try {
			await saveSavedFields( values );
			notice.success(
				__( 'Saved fields updated.', 'rtcamp-amc-copilot' )
			);
		} catch ( err ) {
			setProblem( err );
		} finally {
			setSaving( false );
		}
	};

	if ( error ) {
		return <ErrorNotice error={ error } />;
	}

	return (
		<>
			<p className="description">
				{ __(
					'Stable facts carried forward into every new report. Publishing a report updates them too.',
					'rtcamp-amc-copilot'
				) }
			</p>
			{ notice.node }
			{ problem && <ErrorNotice error={ problem } /> }
			{ ! data && <Loading /> }
			{ data && (
				<SimpleTable
					data={ rows }
					fields={ fields }
					exportName="amc-settings-saved-fields"
					label={ __( 'Saved report fields', 'rtcamp-amc-copilot' ) }
				/>
			) }
			<p className="submit">
				<Button
					variant="primary"
					onClick={ save }
					isBusy={ saving }
					disabled={ ! values || saving }
					accessibleWhenDisabled
				>
					{ __( 'Save saved fields', 'rtcamp-amc-copilot' ) }
				</Button>
			</p>
		</>
	);
}

const TABS = () => [
	{ name: 'general', title: __( 'General', 'rtcamp-amc-copilot' ) },
	{ name: 'schedule', title: __( 'Check schedule', 'rtcamp-amc-copilot' ) },
	{ name: 'access', title: __( 'Engineers', 'rtcamp-amc-copilot' ) },
	{ name: 'fields', title: __( 'Report fields', 'rtcamp-amc-copilot' ) },
];

/**
 * Settings screen (`GET`/`POST /settings`), laid out like core's settings
 * pages (`form-table`, a "Save" button under the form), in four tabs:
 * General (site, notifications, smoke credentials, data and kill switch,
 * report disclaimers), Check schedule, Engineers with access, and saved
 * report fields.
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
	const [ errors, setErrors ] = useState( {} );
	const [ tab, setTab ] = useState( 'general' );
	// TabPanel is uncontrolled: remounting it with a new key is how a failed
	// save switches back to the tab holding the invalid field.
	const [ tabsKey, setTabsKey ] = useState( 0 );
	const pendingFocus = useRef( null );
	const tabsRef = useRef( null );
	const recipientsRef = useRef( null );
	const digestTimeRef = useRef( null );

	useEffect( () => {
		if ( data ) {
			setForm( data );
			setRecipientsText( data.recipients.join( '\n' ) );
			setOverrides( data.checks );
		}
	}, [ data ] );

	// Name the tab list (TabPanel has no prop for it) and move focus to a
	// field a failed save pointed at once its tab is showing.
	useEffect( () => {
		tabsRef.current
			?.querySelector( '[role="tablist"]' )
			?.setAttribute(
				'aria-label',
				__( 'Settings sections', 'rtcamp-amc-copilot' )
			);
		if ( pendingFocus.current?.current ) {
			pendingFocus.current.current.focus();
			pendingFocus.current = null;
		}
	} );

	const focusField = ( ref ) => {
		if ( 'general' === tab ) {
			ref.current?.focus();
			return;
		}
		pendingFocus.current = ref;
		setTab( 'general' );
		setTabsKey( ( key ) => key + 1 );
	};

	const set = ( key ) => ( value ) => {
		setErrors( ( current ) => ( { ...current, [ key ]: undefined } ) );
		setForm( ( current ) => ( { ...current, [ key ]: value } ) );
	};
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
			( checksResource.data ?? [] )
				.map( ( check ) => ( {
					id: check.id,
					label: check.label,
					group_label: check.group_label,
					default_schedule: check.default_schedule,
					enabled: overrides[ check.id ]?.enabled ?? true,
					interval: overrides[ check.id ]?.interval ?? '',
				} ) )
				.sort(
					( a, b ) =>
						a.group_label.localeCompare( b.group_label ) ||
						a.label.localeCompare( b.label )
				),
		[ checksResource.data, overrides ]
	);

	const checkFields = useMemo(
		() => [
			textField( 'label', __( 'Check', 'rtcamp-amc-copilot' ), {
				render: ( { item } ) => (
					<>
						<strong>{ item.label }</strong>
						<br />
						<code>{ item.id }</code>
					</>
				),
			} ),
			textField( 'id', __( 'ID', 'rtcamp-amc-copilot' ) ),
			textField( 'group_label', __( 'Group', 'rtcamp-amc-copilot' ) ),
			customField(
				'enabled',
				__( 'Enabled', 'rtcamp-amc-copilot' ),
				( item ) =>
					item.enabled
						? __( 'On', 'rtcamp-amc-copilot' )
						: __( 'Off', 'rtcamp-amc-copilot' ),
				( item ) => (
					<label htmlFor={ `amc-check-enabled-${ item.id }` }>
						<input
							id={ `amc-check-enabled-${ item.id }` }
							type="checkbox"
							checked={ item.enabled }
							onChange={ ( event ) =>
								patchCheck( item.id, {
									enabled: event.target.checked,
								} )
							}
						/>{ ' ' }
						{ __( 'Enabled', 'rtcamp-amc-copilot' ) }
						<span className="screen-reader-text">
							{ `: ${ item.label }` }
						</span>
					</label>
				)
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
						<select
							aria-label={ sprintf(
								/* translators: %s: check name. */
								__( 'Interval for %s', 'rtcamp-amc-copilot' ),
								item.label
							) }
							value={ item.interval }
							onChange={ ( event ) =>
								patchCheck( item.id, {
									interval: event.target.value,
								} )
							}
						>
							<option value="">
								{ sprintf(
									/* translators: %s: default interval, e.g. Daily. */
									__( 'Default (%s)', 'rtcamp-amc-copilot' ),
									intervalLabel( item.default_schedule )
								) }
							</option>
							{ getIntervals().map( ( option ) => (
								<option
									key={ option.value }
									value={ option.value }
								>
									{ option.label }
								</option>
							) ) }
						</select>
					)
			),
		],
		[ patchCheck ]
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
			const message = sprintf(
				/* translators: %s: email address. */
				__(
					'"%s" is not a valid email address.',
					'rtcamp-amc-copilot'
				),
				badAddress
			);
			setErrors( { recipients: message } );
			notice.error( message );
			focusField( recipientsRef );
			return;
		}
		if ( ! /^\d{2}:\d{2}$/.test( form.digest_time ) ) {
			const message = __(
				'Enter the digest time as HH:MM.',
				'rtcamp-amc-copilot'
			);
			setErrors( { digest_time: message } );
			notice.error( message );
			focusField( digestTimeRef );
			return;
		}
		setErrors( {} );

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

	const saveButton = (
		<p className="submit">
			<Button
				variant="primary"
				onClick={ save }
				isBusy={ saving }
				disabled={ ! form || saving }
				accessibleWhenDisabled
			>
				{ saving
					? __( 'Saving…', 'rtcamp-amc-copilot' )
					: __( 'Save settings', 'rtcamp-amc-copilot' ) }
			</Button>
		</p>
	);

	const general = () => (
		<>
			<h3>{ __( 'Site', 'rtcamp-amc-copilot' ) }</h3>
			<table className="form-table" role="presentation">
				<tbody>
					<Row
						id="amc-setting-site-type"
						label={ __( 'Site type', 'rtcamp-amc-copilot' ) }
						help={ __(
							'Decides whether the latest post or the latest product is a default smoke URL.',
							'rtcamp-amc-copilot'
						) }
					>
						<select
							id="amc-setting-site-type"
							value={ form.site_type }
							aria-describedby="amc-setting-site-type-description"
							onChange={ ( event ) =>
								set( 'site_type' )( event.target.value )
							}
						>
							<option value="content">
								{ __(
									'Content site (latest post)',
									'rtcamp-amc-copilot'
								) }
							</option>
							<option value="ecommerce">
								{ __(
									'Shop (latest product)',
									'rtcamp-amc-copilot'
								) }
							</option>
						</select>
					</Row>
					<Row
						id="amc-setting-environment"
						label={ __(
							'Environment label for reports',
							'rtcamp-amc-copilot'
						) }
					>
						<select
							id="amc-setting-environment"
							value={ form.environment }
							onChange={ ( event ) =>
								set( 'environment' )( event.target.value )
							}
						>
							{ environmentOptions().map( ( option ) => (
								<option
									key={ option.value }
									value={ option.value }
								>
									{ option.label }
								</option>
							) ) }
						</select>
					</Row>
					<Row
						id="amc-setting-contract-start"
						label={ __(
							'Contract start date',
							'rtcamp-amc-copilot'
						) }
						help={ __(
							'Quarterly and annual reports follow this date; the calendar is used when empty.',
							'rtcamp-amc-copilot'
						) }
					>
						<input
							id="amc-setting-contract-start"
							type="date"
							value={ form.contract_start ?? '' }
							aria-describedby="amc-setting-contract-start-description"
							onChange={ ( event ) =>
								set( 'contract_start' )( event.target.value )
							}
						/>
					</Row>
				</tbody>
			</table>

			<h3>{ __( 'Notifications', 'rtcamp-amc-copilot' ) }</h3>
			<table className="form-table" role="presentation">
				<tbody>
					<Row
						id="amc-setting-recipients"
						label={ __( 'Recipients', 'rtcamp-amc-copilot' ) }
						help={ __(
							'One email address per line. Leave empty to email every engineer.',
							'rtcamp-amc-copilot'
						) }
						error={ errors.recipients }
					>
						<textarea
							id="amc-setting-recipients"
							className="large-text code"
							rows={ 4 }
							value={ recipientsText }
							ref={ recipientsRef }
							aria-invalid={ !! errors.recipients }
							aria-describedby="amc-setting-recipients-description"
							onChange={ ( event ) => {
								setErrors( ( current ) => ( {
									...current,
									recipients: undefined,
								} ) );
								setRecipientsText( event.target.value );
							} }
						/>
					</Row>
					<Row
						id="amc-setting-digest-time"
						label={ __(
							'Daily digest time',
							'rtcamp-amc-copilot'
						) }
						help={ __( 'In site time.', 'rtcamp-amc-copilot' ) }
						error={ errors.digest_time }
					>
						<input
							id="amc-setting-digest-time"
							type="time"
							value={ form.digest_time }
							ref={ digestTimeRef }
							aria-invalid={ !! errors.digest_time }
							aria-describedby="amc-setting-digest-time-description"
							onChange={ ( event ) =>
								set( 'digest_time' )( event.target.value )
							}
						/>
					</Row>
					<Row
						id="amc-setting-alerts-per-hour"
						label={ __(
							'Immediate alerts per hour',
							'rtcamp-amc-copilot'
						) }
						help={ __(
							'Alerts over this limit go into the next digest.',
							'rtcamp-amc-copilot'
						) }
					>
						<input
							id="amc-setting-alerts-per-hour"
							type="number"
							className="small-text"
							min={ 0 }
							max={ 100 }
							value={ form.immediate_alerts_per_hour }
							aria-describedby="amc-setting-alerts-per-hour-description"
							onChange={ ( event ) =>
								set( 'immediate_alerts_per_hour' )(
									event.target.value
								)
							}
						/>
					</Row>
					<CheckboxRow
						id="amc-setting-notify-client"
						title={ __( 'Client emails', 'rtcamp-amc-copilot' ) }
						label={ __(
							'Email client administrators when a report is published',
							'rtcamp-amc-copilot'
						) }
						checked={ !! form.notify_client_on_publish }
						onChange={ set( 'notify_client_on_publish' ) }
					/>
				</tbody>
			</table>

			<h3>{ __( 'Smoke check credentials', 'rtcamp-amc-copilot' ) }</h3>
			<p>
				{ __(
					'Optional basic-auth credentials for sites behind HTTP authentication.',
					'rtcamp-amc-copilot'
				) }
			</p>
			{ ! form.loopback_auth.encryption_available && (
				<div className="notice notice-warning inline">
					<p>
						{ __(
							'A password cannot be stored until AMC_COPILOT_ENCRYPTION_KEY is defined in wp-config.php.',
							'rtcamp-amc-copilot'
						) }
					</p>
				</div>
			) }
			<table className="form-table" role="presentation">
				<tbody>
					<Row
						id="amc-setting-loopback-username"
						label={ __( 'Username', 'rtcamp-amc-copilot' ) }
					>
						<input
							id="amc-setting-loopback-username"
							type="text"
							className="regular-text"
							autoComplete="off"
							value={ form.loopback_auth.username }
							onChange={ ( event ) =>
								setNested(
									'loopback_auth',
									'username'
								)( event.target.value )
							}
						/>
					</Row>
					<Row
						id="amc-setting-loopback-password"
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
					>
						<input
							id="amc-setting-loopback-password"
							type="password"
							className="regular-text"
							autoComplete="new-password"
							value={ password }
							disabled={
								! form.loopback_auth.encryption_available
							}
							aria-describedby="amc-setting-loopback-password-description"
							onChange={ ( event ) =>
								setPassword( event.target.value )
							}
						/>
					</Row>
				</tbody>
			</table>

			<h3>{ __( 'Data and kill switch', 'rtcamp-amc-copilot' ) }</h3>
			<table className="form-table" role="presentation">
				<tbody>
					<Row
						id="amc-setting-retention"
						label={ __(
							'Keep check history for',
							'rtcamp-amc-copilot'
						) }
						help={ __(
							'Sampled slow queries are always kept for 90 days.',
							'rtcamp-amc-copilot'
						) }
					>
						<input
							id="amc-setting-retention"
							type="number"
							className="small-text"
							min={ 1 }
							max={ 60 }
							value={ form.retention_months }
							aria-describedby="amc-setting-retention-description"
							onChange={ ( event ) =>
								set( 'retention_months' )( event.target.value )
							}
						/>{ ' ' }
						{ __( 'months', 'rtcamp-amc-copilot' ) }
					</Row>
					<CheckboxRow
						id="amc-setting-kill-switch"
						title={ __( 'Kill switch', 'rtcamp-amc-copilot' ) }
						label={ __(
							'Stop all capture and scheduled checks',
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
							!! ( form.kill_switch_locked || form.kill_switch )
						}
						disabled={ form.kill_switch_locked }
						onChange={ set( 'kill_switch' ) }
					/>
				</tbody>
			</table>

			<h3>{ __( 'Report disclaimers', 'rtcamp-amc-copilot' ) }</h3>
			<p>
				{ __(
					'Leave a field empty to use the report block’s built-in text.',
					'rtcamp-amc-copilot'
				) }
			</p>
			<table className="form-table" role="presentation">
				<tbody>
					{ [
						[ 'backups', __( 'Backups', 'rtcamp-amc-copilot' ) ],
						[ 'hosting', __( 'Hosting', 'rtcamp-amc-copilot' ) ],
						[ 'domains', __( 'Domains', 'rtcamp-amc-copilot' ) ],
						[
							'accessibility',
							__( 'Accessibility', 'rtcamp-amc-copilot' ),
						],
					].map( ( [ key, label ] ) => (
						<Row
							key={ key }
							id={ `amc-setting-disclaimer-${ key }` }
							label={ label }
						>
							<textarea
								id={ `amc-setting-disclaimer-${ key }` }
								className="large-text"
								rows={ 3 }
								value={ form.disclaimers[ key ] ?? '' }
								onChange={ ( event ) =>
									setNested(
										'disclaimers',
										key
									)( event.target.value )
								}
							/>
						</Row>
					) ) }
				</tbody>
			</table>
			{ saveButton }
		</>
	);

	const schedule = () => (
		<>
			<p className="description">
				{ __(
					'Turn checks off or change how often they run. Event checks run when something happens on the site.',
					'rtcamp-amc-copilot'
				) }
			</p>
			{ checksResource.error && (
				<ErrorNotice error={ checksResource.error } />
			) }
			{ ! checksResource.data && ! checksResource.error && <Loading /> }
			{ checksResource.data && (
				<SimpleTable
					data={ checkRows }
					fields={ checkFields }
					columns={ [
						'label',
						'group_label',
						'enabled',
						'interval',
					] }
					exportName="amc-settings-checks"
					label={ __( 'Check schedule', 'rtcamp-amc-copilot' ) }
				/>
			) }
			{ saveButton }
		</>
	);

	const panels = {
		general,
		schedule,
		access: () => <Engineers />,
		fields: () => <SavedFieldsEditor />,
	};

	return (
		<ScreenState
			screen="settings"
			isLoading={ isLoading }
			error={ error }
			onRetry={ reload }
			hasData={ !! form }
		>
			{ notice.node }
			{ form && (
				<TabPanel
					key={ tabsKey }
					className="amc-tabs"
					tabs={ TABS() }
					initialTabName={ tab }
					onSelect={ setTab }
					ref={ tabsRef }
				>
					{ ( current ) => (
						<div className="amc-tab-content">
							{ panels[ current.name ]() }
						</div>
					) }
				</TabPanel>
			) }
		</ScreenState>
	);
}
