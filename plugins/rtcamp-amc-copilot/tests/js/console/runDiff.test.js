/**
 * Internal dependencies
 */
import {
	describeValue,
	diffRunData,
} from '../../../src/js/console/utils/runDiff';

describe( 'diffRunData', () => {
	it( 'reports nothing for identical data', () => {
		expect( diffRunData( { a: 1, b: [ 1 ] }, { a: 1, b: [ 1 ] } ) ).toEqual(
			[]
		);
	} );

	it( 'reports added, removed and changed keys in key order', () => {
		expect(
			diffRunData(
				{ total: 10, gone: true, same: 'x' },
				{ total: 12, same: 'x', fresh: 'y' }
			)
		).toEqual( [
			{ path: 'total', kind: 'changed', before: 10, after: 12 },
			{ path: 'gone', kind: 'removed', before: true, after: undefined },
			{ path: 'fresh', kind: 'added', before: undefined, after: 'y' },
		] );
	} );

	it( 'walks nested objects into dot paths', () => {
		expect(
			diffRunData(
				{ counts: { open: 1, closed: 2 } },
				{ counts: { open: 3, closed: 2 } }
			)
		).toEqual( [
			{ path: 'counts.open', kind: 'changed', before: 1, after: 3 },
		] );
	} );

	it( 'compares lists and deep objects as a whole', () => {
		const changes = diffRunData(
			{ found: [ 'a.php' ], deep: { x: { y: 1 } } },
			{ found: [ 'a.php', 'b.php' ], deep: { x: { y: 2 } } }
		);
		expect( changes.map( ( c ) => c.path ) ).toEqual( [
			'found',
			'deep.x',
		] );
	} );

	it( 'treats missing data as empty', () => {
		expect( diffRunData( null, { a: 1 } ) ).toEqual( [
			{ path: 'a', kind: 'added', before: undefined, after: 1 },
		] );
		expect( diffRunData( { a: 1 }, undefined ) ).toEqual( [
			{ path: 'a', kind: 'removed', before: 1, after: undefined },
		] );
	} );
} );

describe( 'describeValue', () => {
	it( 'summarises lists and objects and prints scalars', () => {
		expect( describeValue( [ 1, 2, 3 ] ) ).toBe( '3 items' );
		expect( describeValue( [ 1 ] ) ).toBe( '1 item' );
		expect( describeValue( { a: 1 } ) ).toBe( '1 field' );
		expect( describeValue( 0 ) ).toBe( '0' );
		expect( describeValue( false ) ).toBe( 'false' );
		expect( describeValue( null ) ).toBe( 'null' );
		expect( describeValue( undefined ) ).toBe( '—' );
	} );
} );
