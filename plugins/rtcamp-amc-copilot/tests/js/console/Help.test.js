/**
 * Internal dependencies
 */
import {
	getScreen,
	getSections,
	sectionFor,
} from '../../../src/js/console/constants';
import Help, {
	getGroups,
	getTopics,
} from '../../../src/js/console/screens/Help';
import { act, describeWithDom, render, settle } from './test-utils';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

jest.setTimeout( 30000 );

const setValue = async ( element, value ) => {
	await act( async () => {
		Object.getOwnPropertyDescriptor(
			window.HTMLInputElement.prototype,
			'value'
		).set.call( element, value );
		element.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );
	} );
	await settle( 20 );
};

const click = async ( node ) => {
	await act( async () => {
		node.click();
	} );
	await settle( 20 );
};

const goTo = ( topic ) =>
	window.history.replaceState(
		{},
		'',
		`/wp-admin/admin.php?page=amc-copilot&screen=help${
			topic ? `&topic=${ topic }` : ''
		}`
	);

const openTitle = ( container ) =>
	container.querySelector( '#amc-help-topic-title' )?.textContent.trim();

const listedTitles = ( container ) =>
	Array.from( container.querySelectorAll( '.amc-help-contents a' ) ).map(
		( link ) => link.textContent.trim()
	);

const topicArg = () =>
	new URL( window.location.href ).searchParams.get( 'topic' );

describe( 'Help topics', () => {
	it( 'have unique ids, a known group, a title and search keywords', () => {
		const topics = getTopics();
		const ids = topics.map( ( topic ) => topic.id );
		const groups = getGroups().map( ( group ) => group.id );

		expect( topics.length ).toBeGreaterThanOrEqual( 8 );
		expect( new Set( ids ).size ).toBe( ids.length );
		topics.forEach( ( topic ) => {
			expect( groups ).toContain( topic.group );
			expect( topic.title ).not.toBe( '' );
			expect( typeof topic.keywords ).toBe( 'string' );
		} );
	} );

	it( 'start with "Start here" and keep each group together, in group order', () => {
		const topics = getTopics();
		const order = getGroups().map( ( group ) => group.id );

		expect( topics[ 0 ].id ).toBe( 'start' );
		topics.slice( 1 ).forEach( ( topic, index ) => {
			expect( order.indexOf( topic.group ) ).toBeGreaterThanOrEqual(
				order.indexOf( topics[ index ].group )
			);
		} );
	} );

	it( 'cover the report workflow a new engineer needs', () => {
		expect( getTopics().map( ( topic ) => topic.id ) ).toEqual(
			expect.arrayContaining( [
				'start',
				'first-report',
				'field-types',
				'schedule',
				'sections',
				'troubleshooting',
				'glossary',
				'cli',
			] )
		);
	} );
} );

describe( 'Help "Under the hood" topics', () => {
	it( 'are their own group, after Reference', () => {
		const groups = getGroups().map( ( group ) => group.id );

		expect( groups ).toContain( 'data' );
		expect( groups.indexOf( 'data' ) ).toBeGreaterThan(
			groups.indexOf( 'reference' )
		);
		expect(
			getTopics()
				.filter( ( topic ) => 'data' === topic.group )
				.map( ( topic ) => topic.id )
		).toEqual( [
			'data-flow',
			'data-tables',
			'data-wordpress',
			'data-checks',
			'data-screens',
		] );
	} );
} );

describeWithDom( 'Help "Under the hood" content', () => {
	afterEach( () => goTo( null ) );

	const textOf = async ( topic ) => {
		goTo( topic );
		const { container, unmount } = await render( <Help /> );
		const text = container.querySelector( '.amc-help-topic' ).textContent;
		await unmount();
		return text;
	};

	it( 'names every plugin table with its retention', async () => {
		const text = await textOf( 'data-tables' );

		[
			'amc_runs',
			'amc_findings',
			'amc_events',
			'amc_queries',
			'amc_changes',
			'amc_snapshots',
			'amc_locks',
		].forEach( ( table ) => expect( text ).toContain( table ) );
		expect( text ).toContain( '90 days' );
	} );

	it( 'names the options, report meta and core data the plugin uses', async () => {
		const text = await textOf( 'data-wordpress' );

		[
			'amc_settings',
			'amc_saved_fields',
			'amc_report',
			'_amc_snapshot',
			'_amc_build_status',
			'update_plugins',
			'amc_manage',
		].forEach( ( name ) => expect( text ).toContain( name ) );
	} );

	it( 'lists what every check reads, including files and remote requests', async () => {
		goTo( 'data-checks' );
		const { container, unmount } = await render( <Help /> );

		expect(
			container.querySelectorAll( '.amc-help-topic tbody tr' )
		).toHaveLength( 32 );
		const text = container.querySelector( '.amc-help-topic' ).textContent;
		[
			'security.checksums',
			'readme.txt',
			'wordpress.org',
			'information_schema',
			'smoke.urls',
		].forEach( ( name ) => expect( text ).toContain( name ) );
		await unmount();
	} );

	it( 'maps each console screen to its source', async () => {
		const text = await textOf( 'data-screens' );

		[ 'Overview', 'Errors', 'Changes', 'Reports', 'GET /overview' ].forEach(
			( name ) => expect( text ).toContain( name )
		);
	} );

	it( 'shows how to look at the raw data yourself', async () => {
		const text = await textOf( 'data-flow' );

		expect( text ).toContain( 'wp db query' );
		expect( text ).toContain( 'wp post meta get' );
	} );
} );

describe( 'Help navigation', () => {
	it( 'is its own section with a label and description', () => {
		expect( sectionFor( 'help' ).slug ).toBe( 'help' );
		expect( getSections().map( ( section ) => section.slug ) ).toContain(
			'help'
		);
		expect( getScreen( 'help' ).label ).toBe( 'Help' );
		expect( getScreen( 'help' ).description ).not.toBe( '' );
	} );
} );

describeWithDom( 'Help screen', () => {
	afterEach( () => goTo( null ) );

	it( 'lists every topic by group and opens only "Start here" first', async () => {
		goTo( null );
		const { container, unmount } = await render( <Help /> );

		expect( container.querySelector( 'h2' ).textContent ).toBe( 'Help' );
		expect(
			Array.from(
				container.querySelectorAll( '.amc-help-group-label' )
			).map( ( node ) => node.textContent )
		).toEqual( getGroups().map( ( group ) => group.label ) );
		expect( listedTitles( container ) ).toEqual(
			getTopics().map( ( topic ) => topic.title )
		);

		// One topic at a time, not one long page.
		expect( container.querySelectorAll( '.amc-help-topic' ) ).toHaveLength(
			1
		);
		expect( openTitle( container ) ).toBe( getTopics()[ 0 ].title );
		expect(
			container.querySelectorAll( '.amc-help-steps > li' )
		).toHaveLength( 7 );
		expect(
			container
				.querySelector( '.amc-help-contents a[aria-current="page"]' )
				.textContent.trim()
		).toBe( getTopics()[ 0 ].title );

		await unmount();
	} );

	it( 'opens the topic named in the URL, and "Start here" for an unknown one', async () => {
		goTo( 'troubleshooting' );
		const first = await render( <Help /> );
		expect( openTitle( first.container ) ).toBe( 'Troubleshooting' );
		await first.unmount();

		goTo( 'no-such-topic' );
		const second = await render( <Help /> );
		expect( openTitle( second.container ) ).toBe( getTopics()[ 0 ].title );
		await second.unmount();
	} );

	it( 'switches topic from the list, updates the URL and focuses the heading', async () => {
		goTo( null );
		const { container, unmount } = await render( <Help /> );
		const link = Array.from(
			container.querySelectorAll( '.amc-help-contents a' )
		).find( ( node ) => node.textContent.trim() === 'Glossary' );

		expect( link.getAttribute( 'href' ) ).toContain( 'topic=glossary' );
		await click( link );

		expect( topicArg() ).toBe( 'glossary' );
		expect( openTitle( container ) ).toBe( 'Glossary' );
		expect( link.getAttribute( 'aria-current' ) ).toBe( 'page' );
		expect( document.activeElement.id ).toBe( 'amc-help-topic-title' );

		await unmount();
	} );

	it( 'steps through topics with Next and Previous', async () => {
		goTo( null );
		const { container, unmount } = await render( <Help /> );
		const [ first, second ] = getTopics();
		const pagerButton = ( prefix ) =>
			Array.from(
				container.querySelectorAll( '.amc-help-pager button' )
			).find( ( node ) => node.textContent.startsWith( prefix ) );

		expect( pagerButton( 'Previous' ) ).toBeUndefined();
		await click( pagerButton( 'Next' ) );
		expect( openTitle( container ) ).toBe( second.title );

		await click( pagerButton( 'Previous' ) );
		expect( openTitle( container ) ).toBe( first.title );

		await unmount();
	} );

	it( 'links to the console screens and topics it talks about', async () => {
		goTo( null );
		const { container, unmount } = await render( <Help /> );
		const hrefs = Array.from( container.querySelectorAll( 'a' ) ).map(
			( link ) => link.getAttribute( 'href' )
		);
		[ 'reports', 'settings', 'overview', 'setup' ].forEach( ( screen ) => {
			expect(
				hrefs.some( ( href ) => href.includes( `screen=${ screen }` ) )
			).toBe( true );
		} );
		await unmount();

		goTo( 'first-report' );
		const step = await render( <Help /> );
		const topicLink = Array.from(
			step.container.querySelectorAll( '.amc-help-topic a' )
		).find( ( node ) => node.textContent === 'Editing a report' );
		expect( topicLink.getAttribute( 'href' ) ).toContain( 'topic=editing' );
		await click( topicLink );
		expect( openTitle( step.container ) ).toBe( 'Editing a report' );
		await step.unmount();
	} );

	it( 'filters the list as you search and says when nothing matches', async () => {
		goTo( null );
		const { container, unmount } = await render( <Help /> );
		const search = container.querySelector( 'input[type="search"]' );

		await setValue( search, 'pending' );
		const titles = listedTitles( container );
		expect( titles ).toContain( 'Troubleshooting' );
		expect( titles.length ).toBeLessThan( getTopics().length );
		expect( titles ).toContain( openTitle( container ) );
		expect( container.textContent ).toMatch( /topics? match/ );

		await setValue( search, 'zzqx-no-such-thing' );
		expect( listedTitles( container ) ).toEqual( [] );
		expect( openTitle( container ) ).toBeUndefined();
		expect( container.textContent ).toContain( 'No help topics match' );

		await setValue( search, '' );
		expect( listedTitles( container ) ).toHaveLength( getTopics().length );

		await unmount();
	} );
} );
