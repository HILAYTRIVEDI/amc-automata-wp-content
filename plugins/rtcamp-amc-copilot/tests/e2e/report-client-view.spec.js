/**
 * A client administrator (`amc_view_reports`, not `amc_manage`) sees published
 * reports only, read only, and cannot reach the console or a draft.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	CLIENT_STORAGE_STATE,
	deleteReport,
	generateReport,
	publishReport,
	setReportTitle,
} = require( './utils/wp-cli' );
const { clientViewPath } = require( './utils/report' );

// Core's wording differs per screen (page, single item, post type list).
const NOT_ALLOWED =
	/Sorry, you are not allowed to (access this page|edit this item|edit posts in this post type)/;

test.describe( 'Client administrator', () => {
	// Log in as the client; the shared admin session is only used for setup.
	test.use( { storageState: CLIENT_STORAGE_STATE } );

	let publishedId;
	let draftId;
	const publishedTitle = 'E2E published maintenance report';
	const draftTitle = 'E2E draft maintenance report';

	test.beforeAll( () => {
		publishedId = generateReport( '2025-04-01', '2025-04-30' );
		setReportTitle( publishedId, publishedTitle );
		publishReport( publishedId );

		draftId = generateReport( '2025-05-01', '2025-05-31' );
		setReportTitle( draftId, draftTitle );
	} );

	test.afterAll( () => {
		deleteReport( publishedId );
		deleteReport( draftId );
	} );

	/**
	 * `fetch` a REST route as the logged-in client, with a REST nonce.
	 *
	 * @param {import('@playwright/test').Page} page Page on the site.
	 * @param {string}                          path REST path.
	 * @return {Promise<{status: number, body: unknown}>} Response.
	 */
	async function restGet( page, path ) {
		await page.goto( '/wp-admin/index.php' );
		return page.evaluate( async ( route ) => {
			const nonce = await (
				await fetch( '/wp-admin/admin-ajax.php?action=rest-nonce' )
			).text();
			const response = await fetch(
				`/index.php?rest_route=${ encodeURIComponent( route ) }`,
				{ headers: { 'X-WP-Nonce': nonce } }
			);
			return {
				status: response.status,
				body: await response.json().catch( () => null ),
			};
		}, path );
	}

	test( 'sees Maintenance Reports in the menu, but not the AMC Copilot console', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/index.php' );
		const menu = page.locator( '#adminmenu' );

		await expect(
			menu.getByRole( 'link', { name: 'Maintenance Reports' } )
		).toBeVisible();
		await expect(
			menu.getByRole( 'link', { name: 'AMC Copilot' } )
		).toHaveCount( 0 );
	} );

	test( 'lists published reports only', async ( { page } ) => {
		await page.goto( `/wp-admin/${ clientViewPath() }` );

		await expect(
			page.getByRole( 'heading', {
				name: 'Maintenance Reports',
				level: 1,
			} )
		).toBeVisible();
		await expect(
			page.getByRole( 'link', { name: publishedTitle } )
		).toBeVisible();
		await expect( page.getByText( draftTitle ) ).toHaveCount( 0 );
	} );

	test( 'opens a published report as a read-only view', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/' + clientViewPath() );
		await page.getByRole( 'link', { name: publishedTitle } ).click();

		const report = page.locator( '.amc-report' );
		await expect( report ).toBeVisible();
		await expect( report.locator( '.amc-section' ) ).toHaveCount( 18 );

		// Nothing to edit: no editable content, no edit links, no editor.
		await expect(
			report.locator( '[contenteditable="true"]' )
		).toHaveCount( 0 );
		await expect( page.locator( 'a[href*="action=edit"]' ) ).toHaveCount(
			0
		);

		await page.goto(
			`/wp-admin/post.php?post=${ publishedId }&action=edit`
		);
		await expect( page.locator( 'body' ) ).toContainText( NOT_ALLOWED );
		await expect( page.locator( '.editor-visual-editor' ) ).toHaveCount(
			0
		);
	} );

	test( 'cannot open a draft report', async ( { page } ) => {
		// The read-only view treats a draft as missing.
		await page.goto( `/wp-admin/${ clientViewPath( draftId ) }` );
		await expect( page.locator( 'body' ) ).toContainText(
			'That report is not available.'
		);

		// The editor and the report list table are closed to them.
		await page.goto( `/wp-admin/post.php?post=${ draftId }&action=edit` );
		await expect( page.locator( 'body' ) ).toContainText( NOT_ALLOWED );
		await expect( page.locator( '.editor-visual-editor' ) ).toHaveCount(
			0
		);

		await page.goto( '/wp-admin/edit.php?post_type=amc_report' );
		await expect( page.locator( 'body' ) ).toContainText( NOT_ALLOWED );
		await expect( page.getByText( draftTitle ) ).toHaveCount( 0 );
	} );

	test( 'cannot open the AMC Copilot console', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=amc-copilot' );

		await expect( page.locator( 'body' ) ).toContainText( NOT_ALLOWED );
		await expect(
			page.locator( '#amc-copilot-root, .amc-console' )
		).toHaveCount( 0 );
	} );

	test( 'the REST API shows published reports only', async ( { page } ) => {
		const list = await restGet( page, '/amc/v1/reports' );
		expect( list.status ).toBe( 200 );
		const ids = list.body.map( ( item ) => item.id );
		expect( ids ).toContain( publishedId );
		expect( ids ).not.toContain( draftId );

		const draft = await restGet( page, `/wp/v2/amc-reports/${ draftId }` );
		expect( [ 401, 403, 404 ] ).toContain( draft.status );

		const snapshot = await restGet(
			page,
			`/amc/v1/reports/${ draftId }/snapshot`
		);
		expect( snapshot.status ).toBe( 403 );

		const generate = await page.evaluate( async () => {
			const nonce = await (
				await fetch( '/wp-admin/admin-ajax.php?action=rest-nonce' )
			).text();
			const response = await fetch(
				'/index.php?rest_route=/amc/v1/reports/generate',
				{
					method: 'POST',
					headers: {
						'X-WP-Nonce': nonce,
						'Content-Type': 'application/json',
					},
					body: JSON.stringify( { period_type: 'month' } ),
				}
			);
			return response.status;
		} );
		expect( generate ).toBe( 403 );
	} );
} );
