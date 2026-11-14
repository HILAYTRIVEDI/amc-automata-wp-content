/**
 * Internal dependencies
 */
import {
	getSavedFields,
	getSettings,
	getSetup,
	resetMocks,
} from '../../../src/js/console/api';
import Setup, {
	getSteps,
	initialValues,
	parseRecipients,
	validateStep,
} from '../../../src/js/console/screens/Setup';
import { act, describeWithDom, render, settle } from './test-utils';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

jest.setTimeout( 30000 );

const button = ( container, text ) =>
	Array.from( container.querySelectorAll( 'button, a' ) ).find(
		( node ) => node.textContent.trim() === text
	);

const click = async ( node ) => {
	await act( async () => {
		node.click();
	} );
	await settle( 50 );
};

const currentStep = ( container ) =>
	container.querySelector( '[aria-current="step"]' )?.textContent;

describe( 'Setup wizard helpers', () => {
	it( 'has the seven steps in order', () => {
		expect( getSteps().map( ( step ) => step.slug ) ).toEqual( [
			'environment',
			'site',
			'hosting',
			'domains',
			'notifications',
			'reports',
			'review',
		] );
	} );

	it( 'parses one recipient per line or comma and finds a bad one', () => {
		expect(
			parseRecipients( 'a@example.org\n b@example.org ,\n' )
		).toEqual( {
			list: [ 'a@example.org', 'b@example.org' ],
			invalid: undefined,
		} );
		expect( parseRecipients( 'a@example.org\nnot-an-email' ).invalid ).toBe(
			'not-an-email'
		);
		expect( parseRecipients( '' ).list ).toEqual( [] );
	} );

	it( 'validates the notifications and report steps', () => {
		const form = {
			recipients: '',
			digest_time: '09:00',
			contract_start: '',
		};
		expect( validateStep( 'notifications', form ) ).toEqual( {} );
		expect(
			validateStep( 'notifications', { ...form, recipients: 'nope' } )
		).toHaveProperty( 'recipients' );
		expect(
			validateStep( 'notifications', { ...form, digest_time: '25:00' } )
		).toHaveProperty( 'digest_time' );
		expect(
			validateStep( 'reports', { ...form, contract_start: '2026-2-1' } )
		).toHaveProperty( 'contract_start' );
		expect(
			validateStep( 'reports', { ...form, contract_start: '2026-02-01' } )
		).toEqual( {} );
		expect( validateStep( 'site', form ) ).toEqual( {} );
	} );

	it( 'prefills only empty saved fields with detected suggestions', () => {
		const values = initialValues(
			{
				detected: {
					environment: 'production',
					suggestions: {
						overview: { client_name: 'Detected Name' },
						hosting: { hosting_type: 'WP Engine' },
					},
				},
			},
			{
				environment: 'production',
				site_type: 'content',
				recipients: [ 'a@example.org' ],
				digest_time: '09:00',
				contract_start: '',
				notify_client_on_publish: true,
			},
			{
				fields: {
					overview: { client_name: 'Stored Name' },
					hosting: { hosting_type: null },
				},
			}
		);

		expect( values.fields.overview.client_name ).toBe( 'Stored Name' );
		expect( values.fields.hosting.hosting_type ).toBe( 'WP Engine' );
		expect( values.fields.hosting.control_panel ).toBe( '' );
		expect( values.suggested ).toEqual( [ 'hosting.hosting_type' ] );
		expect( values.form.environment ).toBe(
			'',
			'The detected environment shows as "detect automatically".'
		);
		expect( values.form.recipients ).toBe( 'a@example.org' );
	} );

	it( 'keeps an environment that differs from the detected one', () => {
		const values = initialValues(
			{ detected: { environment: 'production' } },
			{ environment: 'staging', recipients: [] },
			{ fields: {} }
		);
		expect( values.form.environment ).toBe( 'staging' );
	} );
} );

describeWithDom( 'Setup wizard (mock data)', () => {
	beforeEach( () => {
		resetMocks();
		window.amcCopilot = { useMocks: true, mockLatency: 0 };
	} );

	afterEach( () => {
		delete window.amcCopilot;
	} );

	it( 'walks every step, saves through the existing routes and finishes', async () => {
		const notice = document.createElement( 'div' );
		notice.className = 'notice amc-setup-notice';
		document.body.appendChild( notice );

		const { container, unmount } = await render( <Setup /> );
		await settle( 100 );

		expect( container.querySelectorAll( '.amc-setup-step' ) ).toHaveLength(
			7
		);
		expect( currentStep( container ) ).toContain( 'Welcome' );
		expect(
			button( container, 'Back' ).getAttribute( 'aria-disabled' )
		).toBe( 'true' );

		// Step 1 → 2 saves the environment and site type.
		await click( container.querySelector( 'input[value="staging"]' ) );
		await click( button( container, 'Next' ) );
		expect( currentStep( container ) ).toContain( 'Client and site' );
		expect( ( await getSettings() ).environment ).toBe( 'staging' );

		// Step 2: the stored client name is kept, an empty field gets the
		// detected domain.
		const inputs = container.querySelectorAll( 'input[type="text"]' );
		expect( inputs[ 0 ].value ).toBe( 'Example Client Ltd.' );
		expect( inputs[ 2 ].value ).toBe( 'example-client.com' );
		expect( container.textContent ).toContain( 'Detected by AMC Copilot.' );
		await click( button( container, 'Next' ) );
		expect( ( await getSavedFields() ).fields.cms.websites_covered ).toBe(
			'example-client.com'
		);

		// Step 3 shows what was detected; Skip moves on without saving.
		expect( currentStep( container ) ).toContain( 'Hosting and backups' );
		expect( container.textContent ).toContain(
			'WP Engine (managed WordPress)'
		);
		await click( button( container, 'Skip' ) );
		expect( currentStep( container ) ).toContain( 'Domains' );
		await click( button( container, 'Next' ) );

		// Step 5: an invalid recipient stops the wizard.
		expect( currentStep( container ) ).toContain( 'Notifications' );
		const textarea = container.querySelector( 'textarea' );
		await act( async () => {
			const setter = Object.getOwnPropertyDescriptor(
				window.HTMLTextAreaElement.prototype,
				'value'
			).set;
			setter.call( textarea, 'not-an-email' );
			textarea.dispatchEvent(
				new window.Event( 'input', { bubbles: true } )
			);
		} );
		await click( button( container, 'Next' ) );
		expect( currentStep( container ) ).toContain( 'Notifications' );
		expect( container.textContent ).toContain( 'Error:' );

		await act( async () => {
			const setter = Object.getOwnPropertyDescriptor(
				window.HTMLTextAreaElement.prototype,
				'value'
			).set;
			setter.call( textarea, 'ops@example.org' );
			textarea.dispatchEvent(
				new window.Event( 'input', { bubbles: true } )
			);
		} );
		await click( button( container, 'Next' ) );
		expect( ( await getSettings() ).recipients ).toEqual( [
			'ops@example.org',
		] );

		// Step 6 → review.
		expect( currentStep( container ) ).toContain( 'Report schedule' );
		await click( button( container, 'Next' ) );
		expect( currentStep( container ) ).toContain( 'Review' );
		expect( container.textContent ).toContain( 'ops@example.org' );
		expect( button( container, 'Skip' ) ).toBeUndefined();

		await click( button( container, 'Finish setup' ) );
		await settle( 50 );

		expect( container.textContent ).toContain( 'Setup complete' );
		expect( ( await getSetup() ).completed ).toBe( true );
		expect( document.querySelector( '.amc-setup-notice' ) ).toBeNull();

		await unmount();
	} );

	it( 'goes back to a step from the review', async () => {
		const { container, unmount } = await render( <Setup /> );
		await settle( 100 );

		for ( let i = 0; i < 6; i++ ) {
			await click( button( container, 'Skip' ) );
		}
		expect( currentStep( container ) ).toContain( 'Review' );

		await click(
			container.querySelector( 'button[aria-label="Edit Notifications"]' )
		);
		expect( currentStep( container ) ).toContain( 'Notifications' );

		await click( button( container, 'Back' ) );
		expect( currentStep( container ) ).toContain( 'Domains' );

		await unmount();
	} );
} );
