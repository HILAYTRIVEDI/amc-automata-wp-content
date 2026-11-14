/**
 * First-run setup wizard: notice, steps, saving and Finish.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { wpCli } = require( './utils/wp-cli' );

/**
 * Forget setup completion and the admin's notice dismissal.
 */
function resetSetup() {
	wpCli( [ 'option', 'delete', 'amc_setup_completed' ] );
	try {
		wpCli( [
			'user',
			'meta',
			'delete',
			'admin',
			'amc_setup_notice_dismissed',
		] );
	} catch {
		// Not set.
	}
}

test.describe( 'Setup wizard', () => {
	test.beforeEach( () => {
		resetSetup();
	} );

	test.afterAll( () => {
		// Leave the site set up so the notice does not show in other specs.
		wpCli( [
			'option',
			'update',
			'amc_setup_completed',
			'{"completed_at":"2026-01-01 00:00:00","user_id":1}',
			'--format=json',
			'--autoload=no',
		] );
	} );

	test( 'the plugins screen links to the wizard while setup is not done', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'plugins.php' );

		const notice = page.locator( '.amc-setup-notice' );
		await expect( notice ).toBeVisible();
		await notice
			.getByRole( 'link', { name: 'Run the setup wizard' } )
			.click();

		await expect( page ).toHaveURL( /page=amc-copilot&screen=setup/ );
		await expect(
			page.getByRole( 'heading', { name: 'Set up AMC Copilot' } )
		).toBeVisible();
		await expect( page.locator( '.amc-setup-notice' ) ).toHaveCount( 0 );
	} );

	test( 'activating the plugin opens the wizard once', async ( {
		admin,
		page,
	} ) => {
		wpCli( [ 'plugin', 'deactivate', 'rtcamp-amc-copilot' ] );

		try {
			await admin.visitAdminPage( 'plugins.php' );
			await page
				.getByRole( 'link', {
					name: 'Activate AMC Copilot',
					exact: true,
				} )
				.click();

			await expect( page ).toHaveURL( /page=amc-copilot&screen=setup/ );

			// Only once.
			await admin.visitAdminPage( 'plugins.php' );
			await expect( page ).toHaveURL( /plugins\.php/ );
		} finally {
			wpCli( [ 'plugin', 'activate', 'rtcamp-amc-copilot' ] );
		}
	} );

	test( 'dismissing the notice lasts', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'plugins.php' );

		const dismissed = page.waitForResponse( /amc\/v1\/setup\/dismiss/ );
		await page
			.locator( '.amc-setup-notice' )
			.getByRole( 'button', { name: /Dismiss/ } )
			.click();
		expect( ( await dismissed ).ok() ).toBe( true );

		await page.reload();
		await expect( page.locator( '.amc-setup-notice' ) ).toHaveCount( 0 );
	} );

	test( 'walks the steps, saves them and finishes', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await admin.visitAdminPage(
			'admin.php',
			'page=amc-copilot&screen=setup'
		);

		const step = page.locator( '[aria-current="step"]' );
		await expect( step ).toContainText( 'Welcome' );
		await page.getByRole( 'radio', { name: 'Staging' } ).check();
		await page.getByRole( 'button', { name: 'Next' } ).click();

		await expect( step ).toContainText( 'Client and site' );
		await page.getByLabel( 'Client name' ).fill( 'E2E Client' );
		await page.getByRole( 'button', { name: 'Next' } ).click();

		await expect( step ).toContainText( 'Hosting and backups' );
		await page.getByRole( 'button', { name: 'Skip' } ).click();

		await expect( step ).toContainText( 'Domains' );
		await page.getByLabel( 'Accessibility standard' ).fill( 'WCAG 2.2 AA' );
		await page.getByRole( 'button', { name: 'Next' } ).click();

		await expect( step ).toContainText( 'Notifications' );
		await page
			.getByLabel( 'Engineer recipient emails' )
			.fill( 'ops@example.org' );
		await page.getByRole( 'button', { name: 'Next' } ).click();

		await expect( step ).toContainText( 'Report schedule' );
		await page.getByLabel( 'Contract start date' ).fill( '2026-02-01' );
		await page.getByRole( 'button', { name: 'Next' } ).click();

		await expect( step ).toContainText( 'Review' );
		await expect( page.getByText( 'E2E Client' ) ).toBeVisible();
		await page.getByLabel( 'Run every check once now' ).uncheck();
		await page.getByRole( 'button', { name: 'Finish setup' } ).click();

		await expect(
			page.getByRole( 'heading', { name: 'Setup complete' } )
		).toBeVisible();

		const settings = await requestUtils.rest( {
			path: '/amc/v1/settings',
		} );
		expect( settings.environment ).toBe( 'staging' );
		expect( settings.recipients ).toEqual( [ 'ops@example.org' ] );
		expect( settings.contract_start ).toBe( '2026-02-01' );

		const saved = await requestUtils.rest( {
			path: '/amc/v1/saved-fields',
		} );
		expect( saved.fields.overview.client_name ).toBe( 'E2E Client' );
		expect( saved.fields.accessibility.defined_standard ).toBe(
			'WCAG 2.2 AA'
		);

		const setup = await requestUtils.rest( { path: '/amc/v1/setup' } );
		expect( setup.completed ).toBe( true );

		// Settings can start the wizard again.
		await admin.visitAdminPage(
			'admin.php',
			'page=amc-copilot&screen=settings'
		);
		await page.getByRole( 'link', { name: 'Run setup again' } ).click();
		await expect( page ).toHaveURL( /screen=setup/ );
		await expect( step ).toContainText( 'Welcome' );
	} );
} );
