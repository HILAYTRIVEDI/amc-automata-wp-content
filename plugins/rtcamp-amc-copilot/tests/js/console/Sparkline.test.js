/**
 * Internal dependencies
 */
import { describeWithDom, expectNoViolations, render } from './test-utils';

let Sparkline;

describeWithDom( 'Sparkline', () => {
	beforeAll( () => {
		Sparkline =
			require( '../../../src/js/console/components/Sparkline' ).default;
	} );

	it( 'is an image with a text alternative giving the facts a sighted user reads off the line', async () => {
		const { container, unmount } = await render(
			<Sparkline values={ [ 120, 80, 200, 150 ] } unit=" ms" />
		);
		const svg = container.querySelector( 'svg' );
		expect( svg.getAttribute( 'role' ) ).toBe( 'img' );
		expect( svg.getAttribute( 'aria-label' ) ).toBe(
			'Trend over 4 points: first 120 ms, latest 150 ms, min 80 ms, max 200 ms'
		);
		expect( svg.getAttribute( 'focusable' ) ).toBe( 'false' );
		await expectNoViolations( container );
		await unmount();
	} );

	it( 'starts the alternative with what the series is, so rows in a table can be told apart', async () => {
		const { container, unmount } = await render(
			<Sparkline
				values={ [ 1, 2 ] }
				label="Response time for https://example.com/"
			/>
		);
		expect(
			container.querySelector( 'svg' ).getAttribute( 'aria-label' )
		).toMatch(
			/^Response time for https:\/\/example\.com\/\. Trend over 2 points/
		);
		await unmount();
	} );

	it( 'keeps decimals that matter and drops noise', async () => {
		const { container, unmount } = await render(
			<Sparkline values={ [ 1.25, 3.1 ] } />
		);
		expect(
			container.querySelector( 'svg' ).getAttribute( 'aria-label' )
		).toContain( 'first 1.3, latest 3.1' );
		await unmount();
	} );

	it( 'says "no data" to assistive technology instead of an unexplained dash', async () => {
		const { container, unmount } = await render(
			<Sparkline values={ [] } label="Response time for /shop" />
		);
		expect( container.querySelector( 'svg' ) ).toBeNull();
		expect(
			container.querySelector( '[aria-hidden="true"]' ).textContent
		).toBe( '—' );
		expect(
			container.querySelector( '.screen-reader-text' ).textContent
		).toBe( 'Response time for /shop: no data' );
		await unmount();
	} );
} );
