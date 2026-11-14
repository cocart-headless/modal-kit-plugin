/**
 * Classic (non-block) themes: render the content after the head, so a modal
 * that's only linked from the content is rendered in the footer with late
 * assets. Checks opening, focus, inert, closing and axe there, and a targeted
 * modal's automatic trigger, in each classic theme the test site has.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { AxeBuilder } = require( '@axe-core/playwright' );
const {
	createModal,
	deleteAllModals,
	freshStorage,
} = require( './utils/modals' );

// Twenty Twenty-One is on every test site; the others are added by
// `npm run test:themes`.
const THEMES = [ 'twentytwentyone', 'kadence', 'hello-elementor' ];

// Targets no page, so the modal is printed only because the page links to it.
const LINKED_ONLY = {
	rules: { showOn: { mode: 'include', include: [ '/no-such-page/' ] } },
};

const LAYOUTS = {
	centered: {},
	drawer: { modalLayout: 'drawer', position: 'right' },
	sheet: { modalLayout: 'sheet' },
	full: { modalLayout: 'full' },
	split: { modalLayout: 'split', position: 'media-left' },
	corner: { modalLayout: 'corner', position: 'bottom-right' },
	bar: { modalLayout: 'bar', position: 'bottom' },
};

let pageUrl;
let installed = [];

test.describe( 'Classic themes', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await deleteAllModals( requestUtils );
		const themes = await requestUtils.rest( { path: '/wp/v2/themes' } );
		installed = themes.map( ( theme ) => theme.stylesheet );
		const created = await requestUtils.createPage( {
			title: 'Classic theme test',
			status: 'publish',
			content: `<!-- wp:paragraph --><p><a href="#modal-classic">Open the modal</a></p><!-- /wp:paragraph -->`,
		} );
		pageUrl = new URL( created.link ).pathname;
	} );

	test.beforeEach( async ( { page } ) => {
		await freshStorage( page );
	} );

	test.afterEach( async ( { requestUtils } ) => {
		await deleteAllModals( requestUtils );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.activateTheme( 'twentytwentyfive' );
		await requestUtils.deleteAllPages();
	} );

	for ( const theme of THEMES ) {
		test.describe( theme, () => {
			test.beforeEach( async ( { requestUtils } ) => {
				test.skip(
					! installed.includes( theme ),
					`${ theme } isn't installed on the test site`
				);
				await requestUtils.activateTheme( theme );
			} );

			test( 'a linked modal opens, focuses its heading, makes the page inert and returns focus', async ( {
				page,
				requestUtils,
			} ) => {
				await createModal( requestUtils, {
					slug: 'classic',
					meta: LINKED_ONLY,
				} );
				await page.goto( pageUrl );
				await page.waitForFunction( () =>
					document.documentElement.classList.contains(
						'modal-kit-js'
					)
				);

				const dialog = page.locator( '#modal-classic' );
				const link = page.getByRole( 'link', {
					name: 'Open the modal',
				} );

				await expect( dialog ).toBeHidden();
				await link.click();
				await expect( dialog ).toBeVisible();
				await expect(
					dialog.getByRole( 'heading', { name: /20% off/ } )
				).toBeFocused();
				expect(
					await dialog.evaluate( ( el ) => el.matches( ':modal' ) )
				).toBe( true );

				// The late stylesheet applied: the close button is a 44px target.
				const close = dialog.getByRole( 'button', { name: 'Close' } );
				const box = await close.boundingBox();
				expect( box.width ).toBeGreaterThanOrEqual( 44 );
				expect( box.height ).toBeGreaterThanOrEqual( 44 );

				await page.keyboard.press( 'Escape' );
				await expect( dialog ).toBeHidden();
				await expect( link ).toBeFocused();

				await link.click();
				await close.click();
				await expect( dialog ).toBeHidden();

				await link.click();
				await expect( dialog ).toBeVisible();
				await page.mouse.click( 5, 400 );
				await expect( dialog ).toBeHidden();
			} );

			test( 'a targeted modal opens on page load', async ( {
				page,
				requestUtils,
			} ) => {
				await createModal( requestUtils, {
					slug: 'classic',
					meta: { triggers: [ { type: 'load', delay: 0 } ] },
				} );
				await page.goto( '/' );
				await expect( page.locator( '#modal-classic' ) ).toBeVisible();
			} );

			test( 'axe finds nothing in an open modal, in every layout', async ( {
				page,
				requestUtils,
			} ) => {
				const found = [];

				for ( const [ name, attributes ] of Object.entries(
					LAYOUTS
				) ) {
					await deleteAllModals( requestUtils );
					await createModal( requestUtils, {
						slug: 'classic',
						attributes,
						meta: LINKED_ONLY,
					} );
					await page.goto( `${ pageUrl }#modal-classic` );
					const dialog = page.locator( '#modal-classic' );
					await expect( dialog ).toBeVisible();

					const { violations } = await new AxeBuilder( { page } )
						.include( '#modal-classic' )
						.analyze();
					for ( const { id, nodes } of violations ) {
						for ( const node of nodes ) {
							found.push(
								`${ name }: ${ id } ${ node.target.join(
									' '
								) }`
							);
						}
					}
				}

				expect( found ).toEqual( [] );
			} );
		} );
	}
} );
