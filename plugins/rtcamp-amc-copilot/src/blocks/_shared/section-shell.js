/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	Button,
	Notice,
	PanelBody,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { pencil } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { effectiveValue, formatValue } from './field-value';

/**
 * Sidebar control for one auto/saved field: shows the value, a pencil
 * button to override it, and Reset when overridden.
 *
 * @param {Object}                    props               Props.
 * @param {Object}                    props.field         Field definition.
 * @param {Object}                    props.values        Snapshot section values.
 * @param {Object}                    props.attributes    Block attributes.
 * @param {( attrs: Object ) => void} props.setAttributes Attribute setter.
 * @return {Element} Control.
 */
function OverrideControl( { field, values, attributes, setAttributes } ) {
	const [ isEditing, setIsEditing ] = useState( false );
	const overrides = attributes.overrides || {};
	const { value, edited } = effectiveValue( field, values, attributes );

	const setOverride = ( next ) => {
		const updated = { ...overrides };
		if ( next === undefined ) {
			delete updated[ field.key ];
		} else {
			updated[ field.key ] = next;
		}
		setAttributes( { overrides: updated } );
	};

	if ( isEditing ) {
		return (
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ field.label }
				value={ overrides[ field.key ] ?? formatValue( value ) }
				onChange={ setOverride }
				onBlur={ () => setIsEditing( false ) }
				help={
					field.source === 'saved'
						? __(
								'Saved fields carry forward to the next report when published.',
								'rtcamp-amc-copilot'
							)
						: undefined
				}
			/>
		);
	}

	return (
		<div className="amc-override-control">
			<strong>{ field.label }</strong>
			<div>
				{ formatValue( value, field.type ) }
				{ edited && (
					<span className="amc-edited-badge">
						{ __( 'Edited', 'rtcamp-amc-copilot' ) }
					</span>
				) }
			</div>
			<Button
				icon={ pencil }
				size="small"
				label={ sprintf(
					/* translators: %s: field label. */
					__( 'Edit %s', 'rtcamp-amc-copilot' ),
					field.label
				) }
				onClick={ () => setIsEditing( true ) }
			/>
			{ edited && (
				<Button
					variant="link"
					onClick={ () => setOverride( undefined ) }
				>
					{ __( 'Reset', 'rtcamp-amc-copilot' ) }
				</Button>
			) }
		</div>
	);
}

/**
 * Wrapper every section block uses: block props, the hide toggle, the
 * override panel for auto/saved fields, the heading, then children.
 *
 * @param {Object}                    props               Props.
 * @param {string}                    props.title         Section title (same as SnapshotSchema).
 * @param {Array}                     props.fields        Field definitions.
 * @param {Object}                    props.values        Snapshot section values.
 * @param {Object}                    props.attributes    Block attributes.
 * @param {( attrs: Object ) => void} props.setAttributes Attribute setter.
 * @param {Element}                   props.children      Section body.
 * @return {Element} Editor markup.
 */
export default function SectionShell( {
	title,
	fields,
	values,
	attributes,
	setAttributes,
	children,
} ) {
	const blockProps = useBlockProps( { className: 'amc-section' } );
	const editable = fields.filter( ( field ) => field.source !== 'engineer' );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Section', 'rtcamp-amc-copilot' ) }>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Hide this section',
							'rtcamp-amc-copilot'
						) }
						help={ __(
							'Hidden sections are left out of the published report.',
							'rtcamp-amc-copilot'
						) }
						checked={ !! attributes.hidden }
						onChange={ ( hidden ) => setAttributes( { hidden } ) }
					/>
				</PanelBody>
				{ editable.length > 0 && (
					<PanelBody
						title={ __(
							'Detected and saved values',
							'rtcamp-amc-copilot'
						) }
						initialOpen={ false }
					>
						{ editable.map( ( field ) => (
							<OverrideControl
								key={ field.key }
								field={ field }
								values={ values }
								attributes={ attributes }
								setAttributes={ setAttributes }
							/>
						) ) }
					</PanelBody>
				) }
			</InspectorControls>
			<section { ...blockProps }>
				{ attributes.hidden && (
					<Notice status="info" isDismissible={ false }>
						{ __(
							'This section is hidden and will not appear in the published report.',
							'rtcamp-amc-copilot'
						) }
					</Notice>
				) }
				<h2 className="wp-block-heading">{ title }</h2>
				{ children }
			</section>
		</>
	);
}
