/**
 * Settings → Updates: Modal Kit's own updates. The update server is faked by
 * the e2e mu-plugin (9.9.9 available, 0.0.9 to roll back to), which also
 * stops rollbacks from replacing the linked plugin.
 */
const { AxeBuilder } = require( '@axe-core/playwright' );
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const PATH = '/modal-kit/v1/updates';

test.describe( 'Updates', () => {
	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils
			.rest( {
				path: PATH,
				method: 'POST',
				data: { channel: 'stable', pause: 0, autoUpdate: false },
			} )
			.catch( () => {} );
	} );

	test( 'automatic updates, channel, pause and rollback need no license', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'edit.php',
			'post_type=mk_modal&page=modal-kit-settings#updates'
		);

		await expect(
			page
				.getByRole( 'navigation', { name: 'Settings sections' } )
				.getByRole( 'link', { name: 'Updates' } )
		).toHaveAttribute( 'aria-current', 'page' );

		const panel = page.locator( '.modal-kit-updates' );
		await expect( panel ).toContainText( 'need no license key' );
		await expect( panel ).toContainText( '9.9.9 is available in Plugins.' );
		// The tab saves as it goes: no Save Changes button.
		await expect(
			page.getByRole( 'button', { name: 'Save Changes' } )
		).toHaveCount( 0 );

		const { violations } = await new AxeBuilder( { page } )
			.include( '.modal-kit-updates' )
			.analyze();
		expect( violations ).toEqual( [] );

		const auto = panel.getByRole( 'checkbox', {
			name: 'Install Modal Kit updates automatically',
		} );
		await auto.check();
		await expect( auto ).toBeChecked();
		await page.reload();
		await expect(
			page.locator( '.modal-kit-updates' ).getByRole( 'checkbox', {
				name: 'Install Modal Kit updates automatically',
			} )
		).toBeChecked();

		// Pre-releases without a license.
		await panel
			.getByRole( 'combobox', { name: 'Release channel' } )
			.selectOption( 'beta' );
		await expect(
			page
				.locator( '.modal-kit-settings__notice' )
				.getByText( 'Release channel changed.' )
		).toBeVisible();
		await expect( panel ).toContainText(
			'New features still being tested'
		);

		await panel
			.getByRole( 'combobox', { name: 'Pause updates for' } )
			.selectOption( '2' );
		await panel.getByRole( 'button', { name: 'Pause updates' } ).click();
		await expect( panel ).toContainText( 'Updates are paused until' );
		await panel.getByRole( 'button', { name: 'Resume updates' } ).click();
		await expect(
			panel.getByRole( 'button', { name: 'Pause updates' } )
		).toBeVisible();

		await panel
			.getByRole( 'button', { name: 'Roll back to an earlier version…' } )
			.click();
		await expect(
			panel.getByRole( 'combobox', { name: 'Earlier version' } )
		).toHaveValue( '0.0.9' );
		await panel.getByRole( 'button', { name: 'Roll back' } ).click();

		const dialog = page.getByRole( 'dialog', {
			name: 'Roll back to 0.0.9?',
		} );
		await expect( dialog ).toContainText(
			'Your modals and settings stay as they are.'
		);
		await dialog
			.getByRole( 'button', { name: 'Roll back to 0.0.9' } )
			.click();
		await expect(
			page
				.locator( '.modal-kit-settings__notice' )
				.getByText( 'Modal Kit was rolled back to 0.0.9.' )
		).toBeVisible();
		// Paused for a week, so the newer version isn't installed straight away.
		await expect( panel ).toContainText( 'Updates are paused until' );
	} );
} );
