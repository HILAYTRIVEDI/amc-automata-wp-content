/**
 * Engineer fields and "Regenerate data" on a report draft.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	deleteReport,
	generateReport,
	runQueuedBuilds,
	wpCli,
} = require( './utils/wp-cli' );
const {
	SECTION_BLOCKS,
	engineerViewPath,
	topLevelBlockNames,
} = require( './utils/report' );

/**
 * Type into the Engineer's notes "Note" field of the open report.
 *
 * @param {import('@playwright/test').FrameLocator} canvas Editor canvas.
 * @param {string}                                  text   Text to type.
 */
async function typeNote( canvas, text ) {
	const note = canvas.getByRole( 'textbox', { name: 'Note', exact: true } );
	await note.click();
	await note.pressSequentially( text );
	await expect( note ).toContainText( text );
}

test.describe( 'Report Engineer fields', () => {
	test( 'an edited Engineer field persists after publishing', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		const id = generateReport( '2025-02-01', '2025-02-28' );
		const note = 'Replaced the contact form plugin (e2e).';

		try {
			await admin.editPost( id );
			await typeNote( editor.canvas, note );
			await editor.publishPost();

			// Back from the server, not from editor state.
			await admin.editPost( id );
			await expect(
				editor.canvas.getByRole( 'textbox', {
					name: 'Note',
					exact: true,
				} )
			).toContainText( note );

			const report = await requestUtils.rest( {
				path: `/wp/v2/amc-reports/${ id }`,
				params: { context: 'edit' },
			} );
			expect( report.status ).toBe( 'publish' );
			expect( report.content.raw ).toContain( note );

			// And it shows on the rendered report.
			await admin.visitAdminPage( engineerViewPath( id ) );
			await expect(
				page.locator( '.amc-section--engineer-notes' )
			).toContainText( note );
		} finally {
			deleteReport( id );
		}
	} );

	test( 'regenerating a draft keeps Engineer fields and custom sections', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		const id = generateReport( '2025-03-01', '2025-03-31' );
		const note = 'Disk usage is fine (e2e).';

		try {
			await admin.editPost( id );
			await typeNote( editor.canvas, note );

			// A custom section after Overview, with a title and some text.
			await page.evaluate( () => {
				const { blocks, data } = window.wp;
				data.dispatch( 'core/block-editor' ).insertBlock(
					blocks.createBlock(
						'amc/custom-section',
						{ title: 'Server move' },
						[
							blocks.createBlock( 'core/paragraph', {
								content: 'Moved to the new cluster.',
							} ),
						]
					),
					1
				);
			} );
			await editor.saveDraft();

			// Make the old snapshot recognisable, then regenerate through the
			// REST route (which queues the build) and run the queued build.
			wpCli( [
				'post',
				'meta',
				'update',
				String( id ),
				'_amc_snapshot_taken_at',
				'stale-snapshot',
			] );
			const queued = await requestUtils.rest( {
				method: 'POST',
				path: `/amc/v1/reports/${ id }/regenerate`,
			} );
			expect( queued.id ).toBe( id );
			runQueuedBuilds();

			const fetchReport = () =>
				requestUtils.rest( {
					path: `/wp/v2/amc-reports/${ id }`,
					params: { context: 'edit' },
				} );
			await expect
				.poll(
					async () => {
						const { meta } = await fetchReport();
						return (
							meta._amc_build_status === 'ready' &&
							meta._amc_snapshot_taken_at !== 'stale-snapshot'
						);
					},
					{
						message: 'The snapshot is rebuilt',
						timeout: 60_000,
					}
				)
				.toBe( true );

			const report = await fetchReport();
			expect( report.status ).toBe( 'draft' );
			expect( report.content.raw ).toContain( note );
			expect( report.content.raw ).toContain( 'Server move' );

			// The editor shows both, with the custom section where it was.
			await admin.editPost( id );
			const expected = [ ...SECTION_BLOCKS ];
			expected.splice( 1, 0, 'amc/custom-section' );
			expect( await topLevelBlockNames( editor ) ).toEqual( expected );
			await expect(
				editor.canvas.getByRole( 'textbox', {
					name: 'Note',
					exact: true,
				} )
			).toContainText( note );
			await expect(
				editor.canvas.getByText( 'Moved to the new cluster.' )
			).toBeVisible();
		} finally {
			deleteReport( id );
		}
	} );
} );
