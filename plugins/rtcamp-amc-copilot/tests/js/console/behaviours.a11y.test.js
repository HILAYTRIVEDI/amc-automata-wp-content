/**
 * Accessibility behaviours of the console, exercised with the real
 * `@wordpress/components` and `@wordpress/dataviews` over the console's mock
 * data (no REST calls).
 */

/**
 * Internal dependencies
 */
import {
	act,
	describeWithDom,
	expectNoViolations,
	render,
	settle,
} from './test-utils';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

jest.setTimeout( 60000 );

let speak;
let api;

const setValue = async ( element, value ) => {
	const proto =
		element instanceof window.HTMLTextAreaElement
			? window.HTMLTextAreaElement.prototype
			: window.HTMLInputElement.prototype;
	await act( async () => {
		Object.getOwnPropertyDescriptor( proto, 'value' ).set.call(
			element,
			value
		);
		element.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );
	} );
};

const clickButton = async ( root, text ) => {
	const button = [ ...root.querySelectorAll( 'button' ) ].find(
		( el ) => el.textContent.trim() === text
	);
	expect( button ).toBeDefined();
	await act( async () => {
		button.click();
	} );
	return button;
};

const press = async ( element, key ) => {
	await act( async () => {
		element.dispatchEvent(
			new window.KeyboardEvent( 'keydown', {
				key,
				bubbles: true,
				cancelable: true,
			} )
		);
	} );
};

describeWithDom( 'Console accessibility behaviours', () => {
	beforeAll( () => {
		window.amcCopilot = { useMocks: true, mockLatency: 0 };
		api = require( '../../../src/js/console/api' );
		( { speak } = require( '@wordpress/a11y' ) );
	} );

	afterAll( () => {
		delete window.amcCopilot;
	} );

	beforeEach( () => {
		api.resetMocks();
		speak.mockReset();
		window.history.replaceState(
			{},
			'',
			'/wp-admin/admin.php?page=amc-copilot'
		);
	} );

	describe( 'ScreenState', () => {
		it( 'announces loading as a status and makes the heading a focus target', async () => {
			const ScreenState =
				require( '../../../src/js/console/components/ScreenState' ).default;
			const { container, unmount } = await render(
				<ScreenState title="Updates" isLoading hasData={ false }>
					<p>never shown</p>
				</ScreenState>
			);
			const status = container.querySelector( '[role="status"]' );
			expect( status.textContent ).toContain( 'Loading…' );
			const heading = container.querySelector( 'h2' );
			expect( heading.textContent ).toBe( 'Updates' );
			expect( heading.getAttribute( 'tabindex' ) ).toBe( '-1' );
			expect(
				container
					.querySelector( 'section' )
					.getAttribute( 'aria-labelledby' )
			).toBe( heading.id );
			await expectNoViolations( container );
			await unmount();
		} );
	} );

	describe( 'DataTable', () => {
		const fields = [
			{
				id: 'name',
				label: 'Name',
				type: 'text',
				enableGlobalSearch: true,
				getValue: ( { item } ) => item.name,
			},
		];

		const renderTable = ( props = {} ) => {
			const DataTable =
				require( '../../../src/js/console/components/DataTable' ).default;
			return render(
				<DataTable
					data={ [
						{ id: 1, name: 'Alpha' },
						{ id: 2, name: 'Beta' },
					] }
					fields={ fields }
					exportName="amc-test"
					{ ...props }
				/>
			);
		};

		it( 'names the table, its region, its search field and its export button', async () => {
			const { container, unmount } = await renderTable( {
				label: 'Last run per group',
			} );
			const region = container.querySelector( '[role="region"]' );
			expect( region.getAttribute( 'aria-label' ) ).toBe(
				'Last run per group'
			);
			expect(
				container.querySelector( 'table' ).getAttribute( 'aria-label' )
			).toBe( 'Last run per group' );
			const search = container.querySelector( 'input[type="search"]' );
			expect( search.labels[ 0 ].textContent ).toBe(
				'Search Last run per group'
			);
			const exportButton = [
				...container.querySelectorAll( 'button' ),
			].find( ( b ) => b.textContent === 'Export CSV' );
			expect( exportButton.getAttribute( 'aria-label' ) ).toBe(
				'Export CSV: Last run per group'
			);
			// Column headers are real, named header cells.
			expect(
				[ ...container.querySelectorAll( 'th' ) ].map( ( th ) =>
					th.textContent.trim()
				)
			).toContain( 'Name' );
			await expectNoViolations( container );
			await unmount();
		} );

		it( 'keeps a plain "Search" label when the table has no name', async () => {
			const { container, unmount } = await renderTable();
			expect(
				container.querySelector( 'input[type="search"]' ).labels[ 0 ]
					.textContent
			).toBe( 'Search' );
			await unmount();
		} );

		it( 'announces the CSV export with its row count', async () => {
			URL.createObjectURL = jest.fn( () => 'blob:x' );
			URL.revokeObjectURL = jest.fn();
			// jsdom cannot follow the download link.
			jest.spyOn(
				window.HTMLAnchorElement.prototype,
				'click'
			).mockImplementation( () => {} );
			const { container, unmount } = await renderTable( {
				label: 'People',
			} );
			await clickButton( container, 'Export CSV' );
			expect( speak ).toHaveBeenCalledWith(
				'CSV export started: 2 rows.',
				'polite'
			);
			await unmount();
		} );
	} );

	describe( 'FindingModal', () => {
		const Harness = ( { findingId } ) => {
			const { useState } = require( '@wordpress/element' );
			const FindingModal =
				require( '../../../src/js/console/components/FindingModal' ).default;
			const [ open, setOpen ] = useState( false );
			return (
				<>
					<button type="button" onClick={ () => setOpen( true ) }>
						Open finding
					</button>
					{ open && (
						<FindingModal
							findingId={ findingId }
							onClose={ () => setOpen( false ) }
						/>
					) }
				</>
			);
		};

		it( 'is a labelled dialog, moves focus in, keeps headings in order, and returns focus on close', async () => {
			const { findings } = await api.getVulnerabilities();
			const { container, unmount } = await render(
				<Harness findingId={ findings[ 0 ].id } />
			);
			const opener = container.querySelector( 'button' );
			opener.focus();
			await act( async () => {
				opener.click();
			} );
			await settle( 200 );

			const dialog = document.querySelector( '[role="dialog"]' );
			expect( dialog ).not.toBeNull();
			const title = document.getElementById(
				dialog.getAttribute( 'aria-labelledby' )
			);
			expect( title.textContent ).toBe(
				`Finding #${ findings[ 0 ].id }`
			);
			// Focus moved into the dialog.
			expect( dialog.contains( document.activeElement ) ).toBe( true );

			// Dialog title is the h1; sections inside are h2 then h3, none skipped.
			const levels = [
				...dialog.querySelectorAll( 'h1, h2, h3, h4' ),
			].map( ( h ) => Number( h.tagName[ 1 ] ) );
			expect( levels[ 0 ] ).toBe( 1 );
			levels.slice( 1 ).forEach( ( level, i ) => {
				expect( level - levels[ i ] ).toBeLessThanOrEqual( 1 );
			} );
			expect( levels ).not.toContain( 4 );

			// The JSON block is a named, focusable, scrollable region.
			const json = dialog.querySelector( 'pre.amc-json' );
			expect( json.getAttribute( 'role' ) ).toBe( 'region' );
			expect( json.getAttribute( 'aria-label' ) ).toBe(
				'Finding data (JSON)'
			);
			expect( json.getAttribute( 'tabindex' ) ).toBe( '0' );
			// Severity is a translated text label, not the raw API value.
			expect(
				dialog.querySelector( 'dl .components-badge__content' )
					.textContent
			).toMatch( /^(Critical|High|Medium|Low|Info)$/ );

			await expectNoViolations( dialog );

			await press( dialog, 'Escape' );
			// The modal closes after its exit animation (or its timeout).
			await settle( 500 );
			expect( document.querySelector( '[role="dialog"]' ) ).toBeNull();
			expect( document.activeElement ).toBe( opener );
			await unmount();
		} );

		it( 'shows a labelled loading status while the finding loads', async () => {
			const FindingModal =
				require( '../../../src/js/console/components/FindingModal' ).default;
			window.amcCopilot.mockLatency = 50;
			const { unmount } = await render(
				<FindingModal findingId={ 1 } onClose={ () => {} } />
			);
			const status = document.querySelector(
				'[role="dialog"] [role="status"]'
			);
			expect( status.textContent ).toContain( 'Loading the finding…' );
			window.amcCopilot.mockLatency = 0;
			await settle( 100 );
			await unmount();
		} );
	} );

	describe( 'Inventory tabs', () => {
		it( 'names the tab list and moves between tabs with the arrow keys', async () => {
			const Inventory =
				require( '../../../src/js/console/screens/Inventory' ).default;
			const { container, unmount } = await render( <Inventory /> );
			await settle( 200 );
			const tablist = container.querySelector( '[role="tablist"]' );
			expect( tablist.getAttribute( 'aria-label' ) ).toBe(
				'Inventory sections'
			);
			const tabs = [ ...container.querySelectorAll( '[role="tab"]' ) ];
			expect( tabs.length ).toBeGreaterThan( 1 );
			expect( tabs[ 0 ].getAttribute( 'aria-selected' ) ).toBe( 'true' );
			// Roving tabindex: only the selected tab is in the tab order.
			expect( tabs[ 0 ].getAttribute( 'tabindex' ) ).not.toBe( '-1' );
			expect( tabs[ 1 ].getAttribute( 'tabindex' ) ).toBe( '-1' );

			await act( async () => tabs[ 0 ].focus() );
			await press( tabs[ 0 ], 'ArrowRight' );
			await settle( 50 );
			expect( document.activeElement ).toBe( tabs[ 1 ] );
			expect( tabs[ 1 ].getAttribute( 'aria-selected' ) ).toBe( 'true' );
			const panel = container.querySelector( '[role="tabpanel"]' );
			expect( panel.getAttribute( 'aria-labelledby' ) ).toBe(
				tabs[ 1 ].id
			);
			await unmount();
		} );
	} );

	describe( 'Settings validation', () => {
		it( 'links the recipients error to the field, marks it invalid, focuses it and speaks it', async () => {
			const Settings =
				require( '../../../src/js/console/screens/Settings' ).default;
			const { container, unmount } = await render( <Settings /> );
			await settle( 200 );
			// Open the Notifications panel.
			await clickButton( container, 'Notifications' );
			const field = container.querySelector( 'textarea' );
			expect( field ).not.toBeNull();
			// Visible, associated label.
			expect(
				container.querySelector( `label[for="${ field.id }"]` )
					.textContent
			).toBe( 'Recipients' );

			await setValue( field, 'engineer@example.com\nnot-an-address' );
			await clickButton( container, 'Save settings' );
			await settle( 50 );

			expect( field.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
			const described = document.getElementById(
				field.getAttribute( 'aria-describedby' )
			);
			expect( described.textContent ).toContain(
				'Error: "not-an-address" is not a valid email address.'
			);
			// The normal help stays too.
			expect( described.textContent ).toContain(
				'One email address per line.'
			);
			expect( document.activeElement ).toBe( field );
			expect( speak ).toHaveBeenCalledWith(
				'"not-an-address" is not a valid email address.',
				'assertive'
			);

			// Editing clears the error.
			await setValue( field, 'engineer@example.com' );
			expect( field.getAttribute( 'aria-invalid' ) ).toBe( 'false' );
			expect(
				document.getElementById(
					field.getAttribute( 'aria-describedby' )
				).textContent
			).not.toContain( 'Error' );
			await unmount();
		} );

		it( 'names the per-check toggles with the check, keeping the visible On/Off text in the name', async () => {
			const Settings =
				require( '../../../src/js/console/screens/Settings' ).default;
			const { container, unmount } = await render( <Settings /> );
			await settle( 200 );
			await clickButton( container, 'Check schedule' );
			await settle( 100 );
			const toggle = container.querySelector(
				'.components-toggle-control input[type="checkbox"]'
			);
			const label = container.querySelector(
				`label[for="${ toggle.id }"]`
			);
			expect( label.textContent ).toMatch( /^Enable .+: (On|Off)$/ );
			expect( label.textContent ).toContain(
				toggle.checked ? 'On' : 'Off'
			);
			await unmount();
		} );
	} );

	describe( 'Smoke URL form', () => {
		it( 'points the URL field at the save error and moves focus to it', async () => {
			const Smoke =
				require( '../../../src/js/console/screens/Smoke' ).default;
			const { container, unmount } = await render( <Smoke /> );
			await settle( 200 );
			await clickButton( container, 'Add URL' );
			const dialog = document.querySelector( '[role="dialog"]' );
			expect( dialog ).not.toBeNull();
			const url = dialog.querySelector( 'input[type="url"]' );
			expect(
				dialog.querySelector( `label[for="${ url.id }"]` ).textContent
			).toBe( 'URL' );

			// This URL is already checked, so the API refuses it.
			await setValue( url, 'https://example-client.com/checkout/' );
			await clickButton( dialog, 'Save' );
			await settle( 50 );

			expect( url.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
			expect(
				document.getElementById(
					url.getAttribute( 'aria-describedby' )
				).textContent
			).toContain( 'That URL is already being checked.' );
			expect( document.activeElement ).toBe( url );
			expect( speak ).toHaveBeenCalledWith(
				'That URL is already being checked.',
				'assertive'
			);
			await unmount();
		} );
	} );

	describe( 'App', () => {
		it( 'announces the new screen on navigation (not on first load) and keeps nav semantics', async () => {
			const App = require( '../../../src/js/console/app' ).default;
			const {
				navigate,
			} = require( '../../../src/js/console/utils/navigation' );
			const { container, unmount } = await render( <App /> );
			await settle( 200 );
			// (The sample-data notice speaks itself; no screen announcement.)
			expect( speak ).not.toHaveBeenCalledWith(
				expect.stringMatching( / screen$/ ),
				expect.anything()
			);
			const nav = container.querySelector( 'nav' );
			expect( nav.getAttribute( 'aria-label' ) ).toBe(
				'AMC Copilot screens'
			);
			expect(
				nav.querySelector( '[aria-current="page"]' ).textContent
			).toBe( 'Overview' );

			await act( async () => navigate( 'updates' ) );
			await settle( 100 );
			expect( speak ).toHaveBeenCalledWith( 'Updates screen', 'polite' );
			expect(
				nav.querySelector( '[aria-current="page"]' ).textContent
			).toBe( 'Updates' );
			// One h1, then the screen's h2.
			expect( container.querySelectorAll( 'h1' ) ).toHaveLength( 1 );
			expect( container.querySelector( 'h2' ).textContent ).toBe(
				'Updates'
			);
			await unmount();
		} );

		it( 'puts focus on the screen heading when a deep-linked modal closes with nothing to return to', async () => {
			const { findings } = await api.getVulnerabilities();
			window.history.replaceState(
				{},
				'',
				`/wp-admin/admin.php?page=amc-copilot&screen=vulnerabilities&finding=${ findings[ 0 ].id }`
			);
			const App = require( '../../../src/js/console/app' ).default;
			const { unmount } = await render( <App /> );
			await settle( 300 );
			const dialog = document.querySelector( '[role="dialog"]' );
			expect( dialog ).not.toBeNull();
			await press( dialog, 'Escape' );
			await settle( 500 );
			expect( document.querySelector( '[role="dialog"]' ) ).toBeNull();
			expect( document.activeElement.id ).toBe( 'amc-screen-title' );
			await unmount();
		} );
	} );
} );
