/**
 * Report blocks in the editor: template, locks, custom sections, hiding.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { deleteReport, generateReport } = require( './utils/wp-cli' );
const {
	SECTION_BLOCKS,
	engineerViewPath,
	topLevelBlockNames,
} = require( './utils/report' );

test.describe( 'Report editor', () => {
	test.describe.configure( { mode: 'serial' } );

	let reportId;

	test.beforeAll( () => {
		reportId = generateReport( '2025-01-01', '2025-01-31' );
	} );

	test.afterAll( () => {
		deleteReport( reportId );
	} );

	test.beforeEach( async ( { admin } ) => {
		await admin.editPost( reportId );
	} );

	test( 'a generated draft holds all 18 section blocks in order', async ( {
		editor,
		page,
	} ) => {
		const names = await topLevelBlockNames( editor );

		expect( names ).toEqual( SECTION_BLOCKS );
		expect( names ).toHaveLength( 18 );

		// They render a real section, not the "block not available" placeholder.
		await expect(
			editor.canvas.locator( '.amc-section' ),
			'Every section block renders in the editor canvas'
		).toHaveCount( 18 );
		await expect(
			page.getByText( 'Your site doesn’t include support' )
		).toHaveCount( 0 );
	} );

	test( 'section blocks cannot be removed or moved, custom sections can be inserted between them', async ( {
		editor,
		page,
	} ) => {
		// Lock state, straight from the block editor store.
		const locks = await page.evaluate( () => {
			const select = window.wp.data.select( 'core/block-editor' );
			return select.getBlocks().map( ( block ) => ( {
				name: block.name,
				canRemove: select.canRemoveBlock( block.clientId ),
				canMove: select.canMoveBlock( block.clientId ),
			} ) );
		} );
		expect( locks ).toHaveLength( 18 );
		for ( const lock of locks ) {
			expect( lock, lock.name ).toMatchObject( {
				canRemove: false,
				canMove: false,
			} );
		}

		// The UI offers neither: no move buttons, no "Remove" menu item, and
		// the Delete key does nothing.
		const cms = editor.canvas.locator( '[data-type="amc/section-cms"]' );
		await editor.selectBlocks( cms );
		const toolbar = page.getByRole( 'toolbar', { name: 'Block tools' } );
		await expect( toolbar ).toBeVisible();
		await expect(
			toolbar.getByRole( 'button', { name: 'Move up' } )
		).toHaveCount( 0 );
		await expect(
			toolbar.getByRole( 'button', { name: 'Move down' } )
		).toHaveCount( 0 );
		await toolbar.getByRole( 'button', { name: 'Options' } ).click();
		const menu = page.getByRole( 'menu', { name: 'Options' } );
		await expect(
			menu.getByRole( 'menuitem', { name: 'Add after' } )
		).toBeVisible();
		await expect(
			menu.getByRole( 'menuitem', { name: /^Delete|^Remove/ } )
		).toHaveCount( 0 );
		await page.keyboard.press( 'Escape' );
		await page.keyboard.press( 'Delete' );
		expect( await topLevelBlockNames( editor ) ).toEqual( SECTION_BLOCKS );

		// Programmatic attempts are refused too.
		const afterAttempts = await page.evaluate( () => {
			const { dispatch, select } = window.wp.data;
			const first = select( 'core/block-editor' ).getBlocks();
			dispatch( 'core/block-editor' ).removeBlock( first[ 3 ].clientId );
			dispatch( 'core/block-editor' ).moveBlockToPosition(
				first[ 0 ].clientId,
				'',
				'',
				5
			);
			return select( 'core/block-editor' )
				.getBlocks()
				.map( ( block ) => block.name );
		} );
		expect( afterAttempts ).toEqual( SECTION_BLOCKS );

		// A custom section goes between two of them, through the UI.
		await editor.selectBlocks( cms );
		await toolbar.getByRole( 'button', { name: 'Options' } ).click();
		await menu.getByRole( 'menuitem', { name: 'Add after' } ).click();
		// "Add after" opens an empty paragraph; the slash inserter swaps it.
		await page.keyboard.type( '/Custom report section' );
		await page
			.getByRole( 'option', { name: 'Custom report section' } )
			.click();

		const expected = [ ...SECTION_BLOCKS ];
		expected.splice( 2, 0, 'amc/custom-section' );
		expect( await topLevelBlockNames( editor ) ).toEqual( expected );

		// The new block is not locked: it can be removed again.
		const customCanRemove = await page.evaluate( () => {
			const select = window.wp.data.select( 'core/block-editor' );
			const custom = select
				.getBlocks()
				.find( ( block ) => block.name === 'amc/custom-section' );
			return select.canRemoveBlock( custom.clientId );
		} );
		expect( customCanRemove ).toBe( true );
	} );

	test( '"Hide this section" removes the section from the rendered view', async ( {
		admin,
		editor,
		page,
	} ) => {
		const hosting = editor.canvas.locator(
			'[data-type="amc/section-hosting"]'
		);
		await editor.selectBlocks( hosting );
		await editor.openDocumentSettingsSidebar();
		await page.getByRole( 'tab', { name: 'Block', exact: true } ).click();

		const toggle = page.getByRole( 'checkbox', {
			name: 'Hide this section',
		} );
		await toggle.check();
		await expect( hosting ).toContainText( 'This section is hidden' );
		await editor.saveDraft();

		await admin.visitAdminPage( engineerViewPath( reportId ) );
		const report = page.locator( '.amc-report' );
		await expect( report ).toBeVisible();
		await expect( report.locator( '.amc-section--hosting' ) ).toHaveCount(
			0
		);
		await expect( report.locator( '.amc-section--cms' ) ).toHaveCount( 1 );
		await expect(
			report.getByRole( 'heading', { name: 'Hosting', exact: true } )
		).toHaveCount( 0 );

		// Un-hiding brings it back.
		await admin.editPost( reportId );
		await editor.selectBlocks(
			editor.canvas.locator( '[data-type="amc/section-hosting"]' )
		);
		await editor.openDocumentSettingsSidebar();
		await page.getByRole( 'tab', { name: 'Block', exact: true } ).click();
		await page
			.getByRole( 'checkbox', { name: 'Hide this section' } )
			.uncheck();
		await editor.saveDraft();
		await admin.visitAdminPage( engineerViewPath( reportId ) );
		await expect(
			page.locator( '.amc-report .amc-section--hosting' )
		).toHaveCount( 1 );
	} );
} );
